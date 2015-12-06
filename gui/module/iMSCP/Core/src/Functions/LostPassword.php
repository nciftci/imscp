<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 *
 * The contents of this file are subject to the Mozilla Public License
 * Version 1.1 (the "License"); you may not use this file except in
 * compliance with the License. You may obtain a copy of the License at
 * http://www.mozilla.org/MPL/
 *
 * Software distributed under the License is distributed on an "AS IS"
 * basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See the
 * License for the specific language governing rights and limitations
 * under the License.
 *
 * The Original Code is "VHCS - Virtual Hosting Control System".
 *
 * The Initial Developer of the Original Code is moleSoftware GmbH.
 * Portions created by Initial Developer are Copyright (C) 2001-2006
 * by moleSoftware GmbH. All Rights Reserved.
 *
 * Portions created by the ispCP Team are Copyright (C) 2006-2010 by
 * isp Control Panel. All Rights Reserved.
 *
 * Portions created by the i-MSCP Team are Copyright (C) 2010-2015 by
 * i-MSCP - internet Multi Server Control Panel. All Rights Reserved.
 */

/**
 * Checks if the GD library is loaded
 *
 * @return bool TRUE if loaded, FALSE otherwise
 */
function check_gd()
{
    return function_exists('imagecreatetruecolor');
}

/**
 * Create captcha image
 *
 * @param  string $strSessionVar
 * @return void
 */
function createImage($strSessionVar)
{
    $cfg = \iMSCP\Core\Application::getInstance()->getConfig();
    $rgBgColor = $cfg['LOSTPASSWORD_CAPTCHA_BGCOLOR'];
    $rgTextColor = $cfg['LOSTPASSWORD_CAPTCHA_TEXTCOLOR'];

    if (!($image = imagecreate($cfg['LOSTPASSWORD_CAPTCHA_WIDTH'], $cfg['LOSTPASSWORD_CAPTCHA_HEIGHT']))) {
        throw new RuntimeException('Cannot initialize new GD image stream.');
    }

    imagecolorallocate($image, $rgBgColor[0], $rgBgColor[1], $rgBgColor[2]);
    $textColor = imagecolorallocate($image, $rgTextColor[0], $rgTextColor[1], $rgTextColor[2]);
    $white = imagecolorallocate($image, 0xFF, 0xFF, 0xFF);
    $nbLetters = 6;
    $x = ($cfg['LOSTPASSWORD_CAPTCHA_WIDTH'] / 2) - ($nbLetters * 20 / 2);
    $y = mt_rand(15, 30);

    $string = '';
    for ($i = 0; $i < $nbLetters; $i++) {
        $iRandVal = strRandom(1);
        $fontFile = 'module/iMSCP/Core/src/resources/fonts/' .
            $cfg['LOSTPASSWORD_CAPTCHA_FONTS'][mt_rand(0, count($cfg['LOSTPASSWORD_CAPTCHA_FONTS']) - 1)];

        imagettftext($image, 20, 0, $x, $y, $textColor, $fontFile, $iRandVal);

        $x += 20;
        $y = mt_rand(15, 25);
        $string .= $iRandVal;
    }

    $_SESSION[$strSessionVar] = $string;

    // Some obfuscation
    for ($i = 0; $i < 5; $i++) {
        $x1 = mt_rand(0, $x - 1);
        $y1 = mt_rand(0, round($y / 10, 0));
        $x2 = mt_rand(0, round($x / 10, 0));
        $y2 = mt_rand(0, $y - 1);

        imageline($image, $x1, $y1, $x2, $y2, $white);

        $x1 = mt_rand(0, $x - 1);
        $y1 = $y - mt_rand(1, round($y / 10, 0));
        $x2 = $x - mt_rand(1, round($x / 10, 0));
        $y2 = mt_rand(0, $y - 1);

        imageline($image, $x1, $y1, $x2, $y2, $white);
    }


    imagepng($image);
    imagedestroy($image);

    /** @var \Zend\Http\PhpEnvironment\Response $response */
    $response = \iMSCP\Core\Application::getInstance()->getServiceManager()->get('Response');
    $response->getHeaders()->addHeaderLine('Content-type', 'image/png');
    $response->sendHeaders();
}

/**
 * Generate random string
 *
 * @param int $length Desired random string length
 * @return string A random string
 */
function strRandom($length)
{
    $length = intval($length);
    $str = '';

    while (strlen($str) < $length) {
        $chr = chr(mt_rand(48, 122));

        if (preg_match('/[\x30-\x39\x41-\x5A\x61-\x7A]/', $chr)) {
            $str .= $chr;
        }
    }

    return $str;
}

/**
 * Remove old keys
 *
 * @param int $ttl
 * @return void
 */
function removeOldKeys($ttl)
{
    exec_query(
        'UPDATE `admin` SET `uniqkey` = NULL, `uniqkey_time` = NULL WHERE `uniqkey_time` < ?',
        date('Y-m-d H:i:s', time() - $ttl * 60)
    );
}

/**
 * Sets unique key
 *
 * @param string $adminName
 * @param string $uniqueKey
 * @return void
 */
function setUniqKey($adminName, $uniqueKey)
{
    exec_query('UPDATE `admin` SET `uniqkey` = ?, `uniqkey_time` = ? WHERE `admin_name` = ?', [
        $uniqueKey, date('Y-m-d H:i:s', time()), $adminName
    ]);
}

/**
 * Set password
 *
 * @param string $uniqueKey
 * @param string $userPassword
 * @return void
 */
function setPassword($uniqueKey, $userPassword)
{
    if ($uniqueKey == '') {
        exit;
    }

    exec_query('UPDATE `admin` SET `admin_pass` = ? WHERE `uniqkey` = ?', [
        \iMSCP\Core\Utils\Crypt::bcrypt($userPassword), $uniqueKey
    ]);
}

/**
 * Checks for unique key existence
 *
 * @param string $uniqueKey
 * @return bool TRUE if the key exists, FALSE otherwise
 */
function uniqueKeyExists($uniqueKey)
{
    $stmt = exec_query('SELECT `uniqkey` FROM `admin` WHERE `uniqkey` = ?', $uniqueKey);
    return (bool)$stmt->rowCount();
}

/**
 * generate unique key
 *
 * @return string Unique key
 */
function uniqkeygen()
{
    $uniqueKey = '';

    while ((uniqueKeyExists($uniqueKey)) || (!$uniqueKey)) {
        $uniqueKey = md5(uniqid(mt_rand()));
    }

    return $uniqueKey;
}

/**
 * Send password
 *
 * @param string $uniqueKey
 * @return bool TRUE when password was sended, FALSE otherwise
 */
function sendPassword($uniqueKey)
{
    $cfg = \iMSCP\Core\Application::getInstance()->getConfig();
    $stmt = exec_query(
        'SELECT `admin_name`, `created_by`, `fname`, `lname`, `email` FROM `admin` WHERE `uniqkey` = ?', $uniqueKey
    );

    if ($stmt->rowCount()) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $adminName = $row['admin_name'];
        $createdBy = $row['created_by'];
        $adminFirstName = $row['fname'];
        $adminLastName = $row['lname'];
        $to = $row['email'];
        $userPassword = \iMSCP\Core\Utils\Crypt::randomStr($cfg['PASSWD_CHARS']);
        setPassword($uniqueKey, $userPassword);
        write_log('Lostpassword: ' . $adminName . ': password updated', E_USER_NOTICE);
        exec_query('UPDATE `admin` SET `uniqkey` = ?, `uniqkey_time` = ? WHERE `uniqkey` = ?', ['', '', $uniqueKey]);

        if ($createdBy == 0) {
            $createdBy = 1;
        }

        $data = get_lostpassword_password_email($createdBy);
        $fromName = $data['sender_name'];
        $fromEmail = $data['sender_email'];
        $subject = $data['subject'];
        $message = $data['message'];
        $baseServerVhostPrefix = $cfg['BASE_SERVER_VHOST_PREFIX'];
        $baseServerVhost = $cfg['BASE_SERVER_VHOST'];
        $baseServerVhostPort = ($baseServerVhostPrefix == 'http://')
            ? (($cfg['BASE_SERVER_VHOST_HTTP_PORT'] == '80') ? '' : ':' . $cfg['BASE_SERVER_VHOST_HTTP_PORT'])
            : (($cfg['BASE_SERVER_VHOST_HTTPS_PORT'] == '443') ? '' : ':' . $cfg['BASE_SERVER_VHOST_HTTPS_PORT']);

        if ($fromName) {
            $from = '"' . $fromName . '" <' . $fromEmail . '>';
        } else {
            $from = $fromEmail;
        }

        $search = [];
        $replace = [];
        $search[] = '{USERNAME}';
        $replace[] = $adminName;
        $search[] = '{NAME}';
        $replace[] = $adminFirstName . " " . $adminLastName;
        $search[] = '{PASSWORD}';
        $replace[] = $userPassword;
        $search[] = '{BASE_SERVER_VHOST_PREFIX}';
        $replace[] = $baseServerVhostPrefix;
        $search[] = '{BASE_SERVER_VHOST}';
        $replace[] = $baseServerVhost;
        $search[] = '{BASE_SERVER_VHOST_PORT}';
        $replace[] = $baseServerVhostPort;
        $subject = str_replace($search, $replace, $subject);
        $message = str_replace($search, $replace, $message);
        $headers = 'From: ' . $from . "\n";
        $headers .= "MIME-Version: 1.0\nContent-Type: text/plain; charset=utf-8\n";
        $headers .= "Content-Transfer-Encoding: 7bit\n";
        $headers .= 'X-Mailer: i-MSCP mailer';
        $mailResult = mail($to, $subject, $message, $headers);
        $mailStatus = ($mailResult) ? 'OK' : 'NOT OK';
        $from = tohtml($from);
        write_log("Lostpassword activated: To: |$to|, From: |$from|, Status: |$mailStatus| !", E_USER_NOTICE);
        return true;
    }

    return false;
}

/**
 * Request password
 *
 * @param string $adminName
 * @return bool TRUE on success, FALSE otherwise
 */
function requestPassword($adminName)
{
    $cfg = \iMSCP\Core\Application::getInstance()->getConfig();
    $stmt = exec_query('SELECT `created_by`, `fname`, `lname`, `email` FROM `admin` WHERE `admin_name` = ?', $adminName);

    if (!$stmt->rowCount()) {
        return false;
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $createdBy = $row['created_by'];
    $adminFirstName = $row['fname'];
    $adminLastName = $row['lname'];
    $to = $row['email'];
    $uniqueKey = uniqkeygen();
    setUniqKey($adminName, $uniqueKey);
    write_log('Lostpassword: ' . $adminName . ': uniqkey created', E_USER_NOTICE);

    if ($createdBy == 0) {
        $createdBy = 1;
    }

    $data = get_lostpassword_activation_email($createdBy);
    $fromName = $data['sender_name'];
    $fromEmail = $data['sender_email'];
    $subject = $data['subject'];
    $message = $data['message'];
    $baseServerVhostPrefix = $cfg['BASE_SERVER_VHOST_PREFIX'];
    $baseServerVhost = $cfg['BASE_SERVER_VHOST'];
    $baseServerVhostPort = ($baseServerVhostPrefix == 'http://')
        ? $cfg['BASE_SERVER_VHOST_HTTP_PORT'] : $cfg['BASE_SERVER_VHOST_HTTPS_PORT'];

    if ($fromName) {
        $from = encode_mime_header($fromName) . " <$fromEmail>";
    } else {
        $from = $fromEmail;
    }

    $link = $baseServerVhostPrefix . $baseServerVhost . ':' . $baseServerVhostPort . $_SERVER["PHP_SELF"] . '?key=' . $uniqueKey;

    $search = [];
    $replace = [];
    $search [] = '{USERNAME}';
    $replace[] = $adminName;
    $search [] = '{NAME}';
    $replace[] = "$adminFirstName $adminLastName";
    $search [] = '{LINK}';
    $replace[] = $link;
    $search [] = '{BASE_SERVER_VHOST_PREFIX}';
    $replace[] = $baseServerVhostPrefix;
    $search [] = '{BASE_SERVER_VHOST}';
    $replace[] = $baseServerVhost;
    $search [] = '{BASE_SERVER_VHOST_PORT}';
    $replace[] = $baseServerVhostPort;
    $subject = str_replace($search, $replace, $subject);
    $message = str_replace($search, $replace, $message);
    $headers = "From: $from\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $headers .= 'X-Mailer: i-MSCP Mailer';
    $mailResult = mail($to, encode_mime_header($subject), $message, $headers, "-f $fromEmail");
    $mailStatus = ($mailResult) ? 'OK' : 'NOT OK';
    $from = tohtml($from);
    write_log("Lostpassword send: To: |$to|, From: |$from|, Status: |$mailStatus| !", E_USER_NOTICE);
    return true;
}
