<?php

/**
 * This file is part of the froxlor project.
 * Copyright (c) 2010 the froxlor Team (see authors).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, you can also view it online at
 * https://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  the authors
 * @author     froxlor team <team@froxlor.org>
 * @license    https://files.froxlor.org/misc/COPYING.txt GPLv2
 */

namespace Froxlor\Ajax;

use Exception;
use DateTime;
use Froxlor\Config\ConfigDisplay;
use Froxlor\Config\ConfigParser;
use Froxlor\CurrentUser;
use Froxlor\Database\Database;
use Froxlor\FileDir;
use Froxlor\Froxlor;
use Froxlor\Http\HttpClient;
use Froxlor\Install\Update;
use Froxlor\Settings;
use Froxlor\UI\Listing;
use Froxlor\UI\Panel\UI;
use Froxlor\UI\Request;
use Froxlor\UI\Response;
use Froxlor\Validate\Validate;
use Froxlor\WebAuthn\FroxlorWebAuthn;

class Ajax
{
	protected string $action;
	protected string $theme;
	protected array $userinfo;

	/**
	 * @throws Exception
	 */
	public function __construct()
	{
		$this->action = Request::any('action');
		$this->theme = Request::any('theme', 'Froxlor');

		UI::sendHeaders();
		UI::sendSslHeaders();
	}

	/**
	 * @throws Exception
	 */
	public function handle()
	{
		$this->userinfo = $this->getValidatedSession();

		switch ($this->action) {
			case 'newsfeed':
				return $this->getNewsfeed();
			case 'updatecheck':
				return $this->getUpdateCheck();
			case 'searchglobal':
				return $this->searchGlobal();
			case 'updatetablelisting':
				return $this->updateTablelisting();
			case 'resettablelisting':
				return $this->resetTablelisting();
			case 'editapikey':
				return $this->editApiKey();
			case 'getConfigDetails':
				return $this->getConfigDetails();
			case 'getConfigJsonExport':
				return $this->getConfigJsonExport();
			case 'loadLanguageString':
				return $this->loadLanguageString();
			case 'webauthn_register_begin':
				return $this->webAuthnRegisterBegin();
			case 'webauthn_register_complete':
				return $this->webAuthnRegisterComplete();
			default:
				return $this->errorResponse('Action not found!');
		}
	}

	/**
	 * @throws Exception
	 */
	private function getValidatedSession(): array
	{
		if (CurrentUser::hasSession() == false) {
			throw new Exception("No valid session");
		}
		return CurrentUser::getData();
	}

	/**
	 * @throws Exception
	 */
	private function getNewsfeed()
	{
		UI::initTwig();

		$feed = "https://inside.froxlor.org/news/";

		// Set custom feed if provided
		$role = Request::get('role');
		if ($role == "customer") {
			$custom_feed = Settings::Get("customer.news_feed_url");
			if (!empty(trim($custom_feed))) {
				$feed = $custom_feed;
			}
		}

		// Check for simplexml_load_file
		if (!function_exists("simplexml_load_file")) {
			return $this->errorResponse([
				"Newsfeed not available due to missing php-simplexml extension",
				"Please install the php-simplexml extension in order to view our newsfeed."
			]);
		}

		// Check for curl_version
		if (!function_exists('curl_version')) {
			return $this->errorResponse([
				"Newsfeed not available due to missing php-curl extension",
				"Please install the php-curl extension in order to view our newsfeed."
			]);
		}

		$output = HttpClient::urlGet($feed);
		$news = simplexml_load_string(trim($output));

		if ($news === false) {
			$err = [];
			foreach (libxml_get_errors() as $error) {
				$err[] = $error->message;
			}
			return $this->errorResponse(
				$err
			);
		}

		// Handle items
		if ($news) {
			$items = null;

			for ($i = 0; $i < 3; $i++) {
				$item = $news->channel->item[$i];

				$title = (string)$item->title;
				$link = (string)$item->link;
				$date = date("d.m.Y", strtotime($item->pubDate));
				$content = preg_replace("/[\r\n]+/", " ", strip_tags($item->description));
				$content = substr($content, 0, 150) . "...";

				$items .= UI::twig()->render(UI::validateThemeTemplate('/user/newsfeeditem.html.twig', $this->theme), [
					'link' => $link,
					'title' => $title,
					'date' => $date,
					'content' => $content
				]);
			}

			return $this->jsonResponse($items);
		} else {
			return $this->errorResponse('No Newsfeeds available at the moment.');
		}
	}

	public function errorResponse($message, int $response_code = 500)
	{
		header("Content-Type: application/json");
		return \Froxlor\Api\Response::jsonErrorResponse($message, $response_code);
	}

	public function jsonResponse($value, int $response_code = 200)
	{
		header("Content-Type: application/json");
		return \Froxlor\Api\Response::jsonResponse($value, $response_code);
	}

	private function getUpdateCheck()
	{
		UI::initTwig();

		try {
			$force = Request::get('force', 0);
			$json_result = \Froxlor\Api\Commands\Froxlor::getLocal($this->userinfo, ['force' => $force])->checkUpdate();
			$result = json_decode($json_result, true)['data'];
			$result['full_version'] = Froxlor::getFullVersion();
			$result['dbversion'] = Froxlor::DBVERSION;
			$uc_data = Update::getUpdateCheckData();
			$result['last_update_check'] = $uc_data['ts'];
			$result['channel'] = Settings::Get('system.update_channel');

			$result_rendered = UI::twig()->render(UI::validateThemeTemplate('/misc/version_top.html.twig', $this->theme), $result);
			return $this->jsonResponse($result_rendered);
		} catch (Exception $e) {
			// don't display anything if just not allowed due to permissions
			if ($e->getCode() != 403) {
				return $this->errorResponse($e->getMessage(), $e->getCode());
			}
		}
	}

	/**
	 * search globally in various resources
	 */
	private function searchGlobal()
	{
		$searchtext = Request::any('searchtext');

		$result = [];

		// settings
		$result_settings = [];
		if (isset($this->userinfo['adminsession']) && $this->userinfo['adminsession'] == 1 && $this->userinfo['change_serversettings'] == 1) {
			$result_settings = GlobalSearch::searchSettings($searchtext, $this->userinfo);
		}

		// all searchable entities
		$result_entities = GlobalSearch::searchGlobal($searchtext, $this->userinfo);

		$result = array_merge($result_settings, $result_entities);

		return $this->jsonResponse($result);
	}

	private function updateTablelisting()
	{
		$columns = [];
		foreach ((Request::post('columns') ?? []) as $value) {
			$columns[] = $value;
		}
		if (!empty($columns)) {
			$columns = Listing::storeColumnListingForUser([Request::get('listing') => $columns]);
			return $this->jsonResponse($columns);
		}
		return $this->errorResponse('At least one column must be selected', 406);
	}

	private function resetTablelisting()
	{
		Listing::deleteColumnListingForUser([Request::get('listing') => []]);
		return $this->jsonResponse([]);
	}

	private function editApiKey()
	{
		$keyid = Request::post('id', 0);
		$allowed_from = Request::post('allowed_from', "");
		$valid_until = Request::post('valid_until', "");

		if (empty($keyid)) {
			return $this->errorResponse('Invalid call', 406);
		}

		// validate allowed_from
		if (!empty($allowed_from)) {
			$ip_list = array_map('trim', explode(",", $allowed_from));
			$_check_list = $ip_list;
			foreach ($_check_list as $idx => $ip) {
				if (Validate::validate_ip2($ip, true, 'invalidip', true, true, true) == false) {
					return $this->errorResponse('Invalid ip address', 406);
				}
				// check for cidr
				if (strpos($ip, '/') !== false) {
					$ipparts = explode("/", $ip);
					// shorten IP
					$ip = inet_ntop(inet_pton($ipparts[0]));
					// re-add cidr
					$ip .= '/' . $ipparts[1];
				} else {
					// shorten IP
					$ip = inet_ntop(inet_pton($ip));
				}
				$ip_list[$idx] = $ip;
			}
			$allowed_from = implode(",", array_unique($ip_list));
		}

		if (!empty($valid_until)) {
			$valid_until_db = DateTime::createFromFormat('Y-m-d\TH:i', $valid_until)->format('U');
		} else {
			$valid_until_db = -1;
		}

		$upd_stmt = Database::prepare("
			UPDATE `" . TABLE_API_KEYS . "` SET
			`valid_until` = :vu, `allowed_from` = :af
			WHERE `id` = :keyid AND `adminid` = :aid AND `customerid` = :cid
		");
		if ((int)$this->userinfo['adminsession'] == 1) {
			$cid = 0;
		} else {
			$cid = $this->userinfo['customerid'];
		}
		Database::pexecute($upd_stmt, [
			'keyid' => $keyid,
			'af' => $allowed_from,
			'vu' => $valid_until_db,
			'aid' => $this->userinfo['adminid'],
			'cid' => $cid
		]);
		return $this->jsonResponse(['allowed_from' => $allowed_from, 'valid_until' => $valid_until]);
	}

	/**
	 * return parsed commands/files of configuration templates
	 */
	private function getConfigDetails()
	{
		if (isset($this->userinfo['adminsession']) && $this->userinfo['adminsession'] == 1 && $this->userinfo['change_serversettings'] == 1) {
			$distribution = Request::post('distro', "");
			$section = Request::post('section', "");
			$daemon = Request::post('daemon', "");

			// validate distribution config-xml exists
			$config_dir = FileDir::makeCorrectDir(Froxlor::getInstallDir() . '/lib/configfiles/');
			if (!file_exists($config_dir . "/" . $distribution . ".xml")) {
				return $this->errorResponse("Unknown distribution. The configuration could not be found.");
			}
			// read in all configurations
			$configfiles = new ConfigParser($config_dir . "/" . $distribution . ".xml");
			// get the services
			$services = $configfiles->getServices();
			// validate selected service exists for this distribution
			if (!isset($services[$section])) {
				return $this->errorResponse("Unknown category for selected distribution");
			}
			// get the daemons
			$daemons = $services[$section]->getDaemons();
			// validate selected daemon exists for this section
			if (!isset($daemons[$daemon])) {
				return $this->errorResponse("Unknown service for selected category");
			}
			// finally the config-steps
			$confarr = $daemons[$daemon]->getConfig();
			// get parsed content
			UI::initTwig();
			$content = ConfigDisplay::fromConfigArr($confarr, $configfiles->distributionEditor, $this->theme);

			return $this->jsonResponse([
				'title' => $configfiles->getCompleteDistroName() . '&nbsp;&raquo;&nbsp' . $services[$section]->title . '&nbsp;&raquo;&nbsp' . $daemons[$daemon]->title,
				'content' => $content
			]);
		}
		return $this->errorResponse('Not allowed', 403);
	}

	/**
	 * download JSON export of config-selection
	 */
	private function getConfigJsonExport()
	{
		if (isset($this->userinfo['adminsession']) && $this->userinfo['adminsession'] == 1 && $this->userinfo['change_serversettings'] == 1) {
			$params = $_GET;
			unset($params['action']);
			unset($params['finish']);
			unset($params['csrf_token']);
			header('Content-disposition: attachment; filename=froxlor-config-' . time() . '.json');
			return $this->jsonResponse($params);
		}
		return $this->errorResponse('Not allowed', 403);
	}

	/**
	 * loads a given language string by its identifier
	 */
	private function loadLanguageString()
	{
		$langid = Request::post('langid', "");
		if (preg_match('/^([a-zA-Z\.]+)$/', $langid)) {
			return $this->jsonResponse(lng($langid));
		}
		return $this->errorResponse('Invalid identifier: ' . $langid, 406);
	}

	/**
	 * Begin WebAuthn credential registration.
	 * Returns PublicKeyCredentialCreationOptions as JSON.
	 */
	private function webAuthnRegisterBegin()
	{
		if (Settings::Get('2fa.enabled') != '1') {
			return $this->errorResponse('2FA not enabled', 403);
		}

		$isAdmin = isset($this->userinfo['adminsession']) && $this->userinfo['adminsession'] == 1;
		$userId = $isAdmin ? (int)$this->userinfo['adminid'] : (int)$this->userinfo['customerid'];
		$userName = $this->userinfo['loginname'];
		$displayName = trim(($this->userinfo['firstname'] ?? '') . ' ' . ($this->userinfo['name'] ?? '')) ?: $userName;
		$userid_type = $isAdmin ? 'admin' : 'customer';

		// Collect already-registered credential IDs so the browser can exclude them
		$cred_stmt = Database::prepare("SELECT `credential_id` FROM `" . TABLE_PANEL_WEBAUTHN_CREDENTIALS . "` WHERE `userid` = :uid AND `userid_type` = :utype");
		$cred_rows = Database::pexecute($cred_stmt, ['uid' => $userId, 'utype' => $userid_type]);
		$existingIds = array_column($cred_rows->fetchAll(PDO::FETCH_ASSOC), 'credential_id');

		try {
			$webAuthn = new FroxlorWebAuthn();
			$createArgs = $webAuthn->getCreateArgs($userId, $userName, $displayName, $existingIds);
			$_SESSION['webauthn_reg_challenge'] = $webAuthn->getChallenge();
			return $this->jsonResponse($createArgs);
		} catch (Exception $e) {
			return $this->errorResponse($e->getMessage());
		}
	}

	/**
	 * Complete WebAuthn credential registration.
	 * Verifies the attestation and stores the new credential.
	 */
	private function webAuthnRegisterComplete()
	{
		if (Settings::Get('2fa.enabled') != '1') {
			return $this->errorResponse('2FA not enabled', 403);
		}

		if (empty($_SESSION['webauthn_reg_challenge'])) {
			return $this->errorResponse('No registration challenge in session', 400);
		}

		$clientDataJSON    = Request::post('clientDataJSON', '');
		$attestationObject = Request::post('attestationObject', '');
		$keyName           = trim(Request::post('key_name', '')) ?: 'Security Key';
		$challenge         = $_SESSION['webauthn_reg_challenge'];
		unset($_SESSION['webauthn_reg_challenge']);

		$isAdmin = isset($this->userinfo['adminsession']) && $this->userinfo['adminsession'] == 1;
		$userId = $isAdmin ? (int)$this->userinfo['adminid'] : (int)$this->userinfo['customerid'];
		$userid_type = $isAdmin ? 'admin' : 'customer';

		// Sanitise key name
		$keyName = mb_substr(htmlspecialchars($keyName, ENT_QUOTES, 'UTF-8'), 0, 100);

		try {
			$webAuthn = new FroxlorWebAuthn();
			$credential = $webAuthn->processCreate($clientDataJSON, $attestationObject, $challenge);
		} catch (Exception $e) {
			return $this->errorResponse('Registration failed: ' . $e->getMessage());
		}

		// Persist the credential
		$ins_stmt = Database::prepare("
			INSERT INTO `" . TABLE_PANEL_WEBAUTHN_CREDENTIALS . "`
			(`userid`, `userid_type`, `credential_id`, `public_key`, `sign_count`, `name`, `created_at`)
			VALUES (:uid, :utype, :cid, :pk, :sc, :name, :ts)
		");
		Database::pexecute($ins_stmt, [
			'uid'   => $userId,
			'utype' => $userid_type,
			'cid'   => $credential->credentialId,
			'pk'    => $credential->publicKey,
			'sc'    => $credential->signCount,
			'name'  => $keyName,
			'ts'    => time(),
		]);

		// Activate FIDO2 as the user's 2FA method if not already set
		$user_table = $isAdmin ? TABLE_PANEL_ADMINS : TABLE_PANEL_CUSTOMERS;
		$user_field = $isAdmin ? 'adminid' : 'customerid';
		$upd_stmt = Database::prepare("UPDATE `" . $user_table . "` SET `type_2fa` = 3, `data_2fa` = '' WHERE `" . $user_field . "` = :uid");
		Database::pexecute($upd_stmt, ['uid' => $userId]);

		return $this->jsonResponse(['success' => true, 'key_name' => $keyName]);
	}
}
