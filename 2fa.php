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

if (!defined('AREA')) {
	header("Location: index.php");
	exit();
}

use Froxlor\Database\Database;
use Froxlor\FroxlorLogger;
use Froxlor\FroxlorTwoFactorAuth;
use Froxlor\Settings;
use Froxlor\UI\Panel\UI;
use Froxlor\UI\Request;
use Froxlor\UI\Response;
use Froxlor\PhpHelper;
use Froxlor\User;
use Froxlor\WebAuthn\FroxlorWebAuthn;

if (Settings::Get('2fa.enabled') != '1') {
	Response::dynamicError('2fa.2fa_not_activated');
}

// This file is being included in admin_index and customer_index
// and therefore does not need to require lib/init.php
if (AREA == 'admin') {
	$upd_stmt = Database::prepare("UPDATE `" . TABLE_PANEL_ADMINS . "` SET `type_2fa` = :t2fa, `data_2fa` = :d2fa WHERE adminid = :id");
	$uid = $userinfo['adminid'];
} elseif (AREA == 'customer') {
	$upd_stmt = Database::prepare("UPDATE `" . TABLE_PANEL_CUSTOMERS . "` SET `type_2fa` = :t2fa, `data_2fa` = :d2fa WHERE customerid = :id");
	$uid = $userinfo['customerid'];
}
$success_message = "";

$tfa = new FroxlorTwoFactorAuth('Froxlor ' . Settings::Get('system.hostname'));

// do the delete and then just show a success-message
if ($action == 'delete') {
	Database::pexecute($upd_stmt, [
		't2fa' => 0,
		'd2fa' => "",
		'id' => $uid
	]);
	// remove all WebAuthn credentials for this user if FIDO2 was active
	if ($userinfo['type_2fa'] == 3) {
		$userid_type = (AREA == 'admin') ? 'admin' : 'customer';
		$del_cred_stmt = Database::prepare("DELETE FROM `" . TABLE_PANEL_WEBAUTHN_CREDENTIALS . "` WHERE `userid` = :uid AND `userid_type` = :utype");
		Database::pexecute($del_cred_stmt, ['uid' => $uid, 'utype' => $userid_type]);
	}
	Response::standardSuccess('2fa.2fa_removed');
} elseif ($action == 'webauthn_delete_credential') {
	// Delete a single registered FIDO2 credential
	if ($userinfo['type_2fa'] != 3) {
		Response::dynamicError('2fa.2fa_not_activated_for_user');
	}
	$credId = (int)Request::post('credential_id');
	$userid_type = (AREA == 'admin') ? 'admin' : 'customer';
	$del_stmt = Database::prepare("DELETE FROM `" . TABLE_PANEL_WEBAUTHN_CREDENTIALS . "` WHERE `id` = :id AND `userid` = :uid AND `userid_type` = :utype");
	Database::pexecute($del_stmt, ['id' => $credId, 'uid' => $uid, 'utype' => $userid_type]);

	// If no credentials left, reset type_2fa to 0
	$count_stmt = Database::prepare("SELECT COUNT(*) FROM `" . TABLE_PANEL_WEBAUTHN_CREDENTIALS . "` WHERE `userid` = :uid AND `userid_type` = :utype");
	$count = (int)Database::pexecute_first($count_stmt, ['uid' => $uid, 'utype' => $userid_type])['COUNT(*)'];
	if ($count === 0) {
		Database::pexecute($upd_stmt, ['t2fa' => 0, 'd2fa' => '', 'id' => $uid]);
	}
	Response::standardSuccess('2fa.2fa_key_removed');
} elseif ($action == 'preadd') {
	$type = Request::post('type_2fa', '0');

	$data = "";
	if ($type > 0) {
		// FIDO2/WebAuthn: do NOT save type_2fa=3 yet – that happens automatically
		// in ajax.php after the first credential is registered. Just render the
		// registration UI so the user can add their first key via AJAX.
		if ($type == 3) {
			UI::twig()->addGlobal('userinfo', $userinfo);
			$log->logAction(FroxlorLogger::USR_ACTION, LOG_NOTICE, "viewed 2fa::fido2 setup");
			UI::view('user/2fa.html.twig', [
				'type_select_values' => [],
				'ga_qrcode' => '',
				'webauthn_credentials' => [],
				'webauthn_setup' => true,
			]);
			exit();
		}

		// generate secret for TOTP
		$data = $tfa->createSecret();

		$userinfo['type_2fa'] = $type;
		$userinfo['data_2fa'] = $data;
		$userinfo['2fa_unsaved'] = true;

		// if type = email, send a code there for confirmation
		if ($type == 1) {
			$code = $tfa->getCode($data);
			$_mailerror = false;
			$mailerr_msg = "";
			$replace_arr = [
				'CODE' => $code
			];
			$mail_body = html_entity_decode(PhpHelper::replaceVariables(lng('mails.2fa.mailbody'), $replace_arr));

			try {
				$mail->Subject = lng('mails.2fa.subject');
				$mail->AltBody = $mail_body;
				$mail->MsgHTML(str_replace("\n", "<br />", $mail_body));
				$mail->AddAddress($userinfo['email'], User::getCorrectUserSalutation($userinfo));
				$mail->Send();
			} catch (\PHPMailer\PHPMailer\Exception $e) {
				$mailerr_msg = $e->errorMessage();
				$_mailerror = true;
			} catch (Exception $e) {
				$mailerr_msg = $e->getMessage();
				$_mailerror = true;
			}

			if ($_mailerror) {
				Response::dynamicError($mailerr_msg);
			}
		}
		UI::twig()->addGlobal('userinfo', $userinfo);
	} else {
		Response::dynamicError('Select one of the possible values for 2FA');
	}
} elseif ($action == 'add') {
	$type = Request::post('type_2fa', '0');
	$data = Request::post('data_2fa', '');
	$code = Request::post('codevalidation', '');

	// validate
	$result = $tfa->verifyCode($data, $code, 3);

	if ($result) {
		if ($type == 0 || $type == 1) {
			// no fixed secret for email validation, the validation code will be set on the fly
			$data = "";
		}
		Database::pexecute($upd_stmt, [
			't2fa' => $type,
			'd2fa' => $data,
			'id' => $uid
		]);
		Response::standardSuccess('2fa.2fa_added', $filename);
	}
	Response::dynamicError('Invalid/wrong code');
}

$log->logAction(FroxlorLogger::USR_ACTION, LOG_NOTICE, "viewed 2fa::overview");

$type_select_values = [];
$ga_qrcode = '';
$webauthn_credentials = [];

if ($userinfo['type_2fa'] == '0') {
	// available types
	$type_select_values = [
		0 => '-',
		1 => 'E-Mail',
		2 => 'Authenticator',
		3 => 'FIDO2 / Passkey'
	];
	asort($type_select_values);
} elseif ($userinfo['type_2fa'] == '1') {
	// email 2fa enabled
} elseif ($userinfo['type_2fa'] == '2') {
	// authenticator 2fa enabled
	$ga_qrcode = $tfa->getQRCodeImageAsDataUri($userinfo['loginname'], $userinfo['data_2fa']);
} elseif ($userinfo['type_2fa'] == '3') {
	// FIDO2/WebAuthn enabled — load registered credentials
	$userid_type = (AREA == 'admin') ? 'admin' : 'customer';
	$cred_stmt = Database::prepare("SELECT `id`, `name`, `created_at` FROM `" . TABLE_PANEL_WEBAUTHN_CREDENTIALS . "` WHERE `userid` = :uid AND `userid_type` = :utype ORDER BY `created_at` ASC");
	$cred_result = Database::pexecute($cred_stmt, ['uid' => $uid, 'utype' => $userid_type]);
	$webauthn_credentials = $cred_result->fetchAll(PDO::FETCH_ASSOC);
}

UI::view('user/2fa.html.twig', [
	'type_select_values' => $type_select_values,
	'ga_qrcode' => $ga_qrcode,
	'webauthn_credentials' => $webauthn_credentials,
]);
