<?php

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2016 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright (c) the authors
 * @author Froxlor team <team@froxlor.org> (2016-)
 * @license GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package Functions
 *
 */
function createDomainZone($domain_id)
{
	// get domain-name
	$dom_stmt = Database::prepare("SELECT * FROM `" . TABLE_PANEL_DOMAINS . "` WHERE id = :did");
	$domain = Database::pexecute_first($dom_stmt, array(
		'did' => $domain_id
	));

	if ($domain['isbinddomain'] != '1') {
		return;
	}

	// select all entries
	$sel_stmt = Database::prepare("SELECT * FROM `" . TABLE_DOMAIN_DNS . "` WHERE domain_id = :did ORDER BY id ASC");
	Database::pexecute($sel_stmt, array(
		'did' => $domain_id
	));
	$dom_entries = $sel_stmt->fetchAll(PDO::FETCH_ASSOC);

	// @TODO alias domains

	// TODO for now, dummy time-periods
	$soa_content = getPrimaryNs($dom_entries) . " " . str_replace('@', '.', Settings::Get('panel.adminmail')) . ". (" . PHP_EOL;
	$soa_content .= $domain['bindserial'] . "\t; serial" . PHP_EOL;
	$soa_content .= "1800\t; refresh (30 mins)" . PHP_EOL;
	$soa_content .= "900\t; retry (15 mins)" . PHP_EOL;
	$soa_content .= "604800\t; expire (7 days)" . PHP_EOL;
	$soa_content .= "1200\t)\t; minimum (20 mins)";

	// create Zone
	$zonefile = "\$TTL " . (int) Settings::Get('system.defaultttl') . PHP_EOL;
	$zonefile .= "\$ORIGIN " . $domain['domain'] . "." . PHP_EOL;
	$zonefile .= formatEntry('@', 'SOA', $soa_content);

	// check for required records
	$required_entries = array();

	addRequiredEntry('@', 'A', $required_entries);
	addRequiredEntry('@', 'AAAA', $required_entries);
	addRequiredEntry('@', 'NS', $required_entries);
	if ($domain['isemaildomain'] === '1') {
		addRequiredEntry('@', 'MX', $required_entries);
	}

	// additional required records by setting
	if ($domain['iswildcarddomain'] == '1') {
		addRequiredEntry('*', 'A', $required_entries);
		addRequiredEntry('*', 'AAAA', $required_entries);
	} else
		if ($domain['wwwserveralias'] == '1') {
			addRequiredEntry('www', 'A', $required_entries);
			addRequiredEntry('www', 'AAAA', $required_entries);
		}

	// additional required records for subdomains
	$subdomains_stmt = Database::prepare("
		SELECT `domain` FROM `" . TABLE_PANEL_DOMAINS . "`
		WHERE `parentdomainid` = :domainid
	");
	Database::pexecute($subdomains_stmt, array(
		'domainid' => $domain_id
	));

	while ($subdomain = $subdomains_stmt->fetch(PDO::FETCH_ASSOC)) {
		// Listing domains is enough as there currently is no support for choosing
		// different ips for a subdomain => use same IPs as toplevel
		addRequiredEntry(str_replace('.' . $domain['domain'], '', $subdomain['domain']), 'A', $required_entries);
		addRequiredEntry(str_replace('.' . $domain['domain'], '', $subdomain['domain']), 'AAAA', $required_entries);

		// Check whether to add a www.-prefix
		if ($domain['iswildcarddomain'] == '1') {
			addRequiredEntry('*.' . str_replace('.' . $domain['domain'], '', $subdomain['domain']), 'A', $required_entries);
			addRequiredEntry('*.' . str_replace('.' . $domain['domain'], '', $subdomain['domain']), 'AAAA', $required_entries);
		} elseif ($domain['wwwserveralias'] == '1') {
			addRequiredEntry('www.' . str_replace('.' . $domain['domain'], '', $subdomain['domain']), 'A', $required_entries);
			addRequiredEntry('www.' . str_replace('.' . $domain['domain'], '', $subdomain['domain']), 'AAAA', $required_entries);
		}
	}

	// now generate all records and unset the required entries we have
	foreach ($dom_entries as $entry) {
		if (array_key_exists($entry['type'], $required_entries) && array_key_exists(md5($entry['record']), $required_entries[$entry['type']])) {
			unset($required_entries[$entry['type']][md5($entry['record'])]);
		}
		$zonefile .= formatEntry($entry['record'], $entry['type'], $entry['content'], $entry['prio'], $entry['ttl']);
	}

	// add missing required entries
	if (! empty($required_entries)) {

		// A / AAAA records
		if (array_key_exists("A", $required_entries) || array_key_exists("AAAA", $required_entries)) {
			$result_ip_stmt = Database::prepare("
				SELECT `p`.`ip` AS `ip`
				FROM `" . TABLE_PANEL_IPSANDPORTS . "` `p`, `" . TABLE_DOMAINTOIP . "` `di`
				WHERE `di`.`id_domain` = :domainid AND `p`.`id` = `di`.`id_ipandports`
				GROUP BY `p`.`ip`;
			");
			Database::pexecute($result_ip_stmt, array(
				'domainid' => $domain_id
			));
			$all_ips = $result_ip_stmt->fetchAll(PDO::FETCH_ASSOC);

			foreach ($all_ips as $ip) {
				foreach ($required_entries as $type => $records) {
					foreach ($records as $record) {
						if ($type == 'A' && filter_var($ip['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
							$zonefile .= formatEntry($record, 'A', $ip['ip']);
						} elseif ($type == 'AAAA' && filter_var($ip['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
							$zonefile .= formatEntry($record, 'AAAA', $ip['ip']);
						}
					}
				}
			}
			unset($required_entries['A']);
			unset($required_entries['AAAA']);
		}

		// NS records
		if (array_key_exists("NS", $required_entries)) {
			if (Settings::Get('system.nameservers') != '') {
				$nameservers = explode(',', Settings::Get('system.nameservers'));
				foreach ($nameservers as $nameserver) {
					$nameserver = trim($nameserver);
					// append dot to hostname
					if (substr($nameserver, - 1, 1) != '.') {
						$nameserver .= '.';
					}
					foreach ($required_entries as $type => $records) {
						if ($type == 'NS') {
							foreach ($records as $record) {
								$zonefile .= formatEntry($record, 'NS', $nameserver);
							}
						}
					}
				}
				unset($required_entries['NS']);
			}
		}

		// MX records
		if (array_key_exists("MX", $required_entries)) {
			if (Settings::Get('system.mxservers') != '') {
				$mxservers = explode(',', Settings::Get('system.mxservers'));
				foreach ($mxservers as $mxserver) {
					if (substr($mxserver, - 1, 1) != '.') {
						$mxserver .= '.';
					}
					// split in prio and server
					$mx_details = explode(" ", $mxserver);
					if (count($mx_details) == 1) {
						$mx_details[1] = $mx_details[0];
						$mx_details[0] = 10;
					}
					foreach ($required_entries as $type => $records) {
						if ($type == 'MX') {
							foreach ($records as $record) {
								$zonefile .= formatEntry($record, 'MX', $mx_details[1], $mx_details[0]);
							}
						}
					}
				}
				unset($required_entries['MX']);
			}
		}
	}

	return $zonefile;
}

function formatEntry($record = '@', $type = 'A', $content = null, $prio = 0, $ttl = 18000, $class = 'IN')
{
	$result = $record . "\t" . $ttl . "\t" . $class . "\t" . $type . "\t" . (($prio >= 0 && ($type == 'MX' || $type == 'SRV')) ? $prio . "\t" : "") . $content . PHP_EOL;
	return $result;
}

function addRequiredEntry($record = '@', $type = 'A', &$required)
{
	if (!isset($required[$type])) {
		$required[$type] = array();
	}
	$required[$type][md5($record)] = $record;
}

function getPrimaryNs($dom_entries)
{
	// go through all records and use the first NS record as primary NS
	foreach ($dom_entries as $entry) {
		if ($entry['type'] == 'NS') {
			return $entry['content'];
		}
	}
	// FIXME use default from settings somehow if none given?
	return 'no.dns-server.given.tld.';
}
