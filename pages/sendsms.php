<?php
#==============================================================================
# LTB Self Service Password
#
# Copyright (C) 2009 Clement OUDOT
# Copyright (C) 2009 LTB-project.org
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# GPL License: http://www.gnu.org/licenses/gpl.txt
#
#==============================================================================

# This page is called to send random generated password to user by SMS

#==============================================================================
# POST parameters
#==============================================================================
# Initiate vars
$result = "";
$login = "";
$sms = "";
$ldap = "";
$userdn = "";
$smstoken = "";

if (!$crypt_tokens ) {
    $result = "crypttokensrequired";
} elseif (isset($_REQUEST["smstoken"]) and isset($_REQUEST["token"]) and isset($_REQUEST["login"])) {
    $token = $_REQUEST["token"];
    $smstoken = $_REQUEST["smstoken"];
    $login = $_REQUEST["login"];
    if ( decrypt($token, $keyphrase) == $smstoken ) {
         $result = "buildtoken";
    } else {
         $result = "tokennotvalid";
    }
} elseif (isset($_REQUEST["sms"]) and isset($_REQUEST["login"])) {
    $sms = decrypt($_REQUEST["sms"], $keyphrase);
    $login = $_REQUEST["login"];
    $result = "sendsms";
} elseif (isset($_REQUEST["login"]) and $_REQUEST["login"]) {
    $login = $_REQUEST["login"];
} else {
    $result = "loginrequired";
}

# Strip slashes added by PHP
$login = stripslashes_if_gpc_magic_quotes($login);

# Check the entered username for characters that our installation doesn't support
if ( $result === "" ) {
    $result = check_username_validity($login,$login_forbidden_chars);
}

#==============================================================================
# Check reCAPTCHA
#==============================================================================
if ( $result === "" ) {
    if ( $use_recaptcha ) {
        $resp = recaptcha_check_answer ($recaptcha_privatekey,
                                $_SERVER["REMOTE_ADDR"],
                                $_POST["recaptcha_challenge_field"],
                                $_POST["recaptcha_response_field"]);
        if (!$resp->is_valid) {
            $result = "badcaptcha";
            error_log("Bad reCAPTCHA attempt with user $login");
        }
    }
}

#==============================================================================
# Check sms
#==============================================================================
if ( $result === "" ) {

    # Connect to LDAP
    $ldap = ldap_connect($ldap_url);
    ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
    if ( $ldap_starttls && !ldap_start_tls($ldap) ) {
        $result = "ldaperror";
        error_log("LDAP - Unable to use StartTLS");
    } else {

    # Bind
    if ( isset($ldap_binddn) && isset($ldap_bindpw) ) {
        $bind = ldap_bind($ldap, $ldap_binddn, $ldap_bindpw);
    } else {
        $bind = ldap_bind($ldap);
    }

    $errno = ldap_errno($ldap);
    if ( $errno ) {
        $result = "ldaperror";
        error_log("LDAP - Bind error $errno (".ldap_error($ldap).")");
    } else {
    
    # Search for user
    $ldap_filter = str_replace("{login}", $login, $ldap_filter);
    $search = ldap_search($ldap, $ldap_base, $ldap_filter);

    $errno = ldap_errno($ldap);
    if ( $errno ) {
        $result = "ldaperror";
        error_log("LDAP - Search error $errno (".ldap_error($ldap).")");
    } else {

    # Get user DN
    $entry = ldap_first_entry($ldap, $search);
    $userdn = ldap_get_dn($ldap, $entry);

    if( !$userdn ) {
        $result = "badcredentials";
        error_log("LDAP - User $login not found");
    }  

    # Get sms values
    $smsValues = ldap_get_values($ldap, $entry, $sms_attribute);

    # Check sms number
    if ( $smsValues["count"] > 0 ) {
        $sms = $smsValues[0];
    }

    if ( !$sms ) {
        $result = "smsnonumber";
        error_log("No SMS number found for user $login");
    } else {
        $displayname = ldap_get_values($ldap, $entry, $ldap_fullname_attribute);
        $smsnum = encrypt($sms, $keyphrase);
        $result = "smsuserfound";
    }


}}}}

#==============================================================================
# Generate sms token and send by sms
#==============================================================================
if ( $result === "sendsms" ) {

    # Generate sms token
    $smstoken = generate_sms_token($sms_token_length);

    # Remove plus and spaces from sms number
    $sms = str_replace('+', '', $sms);
    $sms = str_replace(' ', '', $sms);

    $data = array( "sms_attribute" => $sms, "smsresetmessage" => $messages['smsresetmessage'], "smstoken" => $smstoken) ;

    # Send message
    if ( send_mail($smsmailto, $mail_from, $smsmail_subject, $sms_message, $data) ) {
        $token = encrypt($smstoken, $keyphrase);
        $result = "smssent";
    } else {
        $result = "smsnotsent";
        error_log("Error while sending sms to $sms (user $login)");
    }

}

#==============================================================================
# Build and store token
#==============================================================================
if ( $result === "buildtoken" ) {

    # Use PHP session to register token
    # We do not generate cookie
    ini_set("session.use_cookies",0);
    ini_set("session.use_only_cookies",1);

    session_name("token");
    session_start();
    $_SESSION['login'] = $login;
    $_SESSION['time']  = time();

    $token = encrypt(session_id(), $keyphrase);

    $result = "redirect";
}

#==============================================================================
# Redirect to resetbytoken page
#==============================================================================
if ( $result === "redirect" ) {

    if ( empty($reset_url) ) {

        # Build reset by token URL
        $method = "http";
        if ( !empty($_SERVER['HTTPS']) ) { $method .= "s"; }
        $server_name = $_SERVER['SERVER_NAME'];
        $server_port = $_SERVER['SERVER_PORT'];
        $script_name = $_SERVER['SCRIPT_NAME'];

        # Force server port if non standard port
        if (   ( $method === "http"  and $server_port != "80"  )
            or ( $method === "https" and $server_port != "443" )
        ) {
            $server_name .= ":".$server_port;
        }

        $reset_url = $method."://".$server_name.$script_name;
    }

    $reset_url .= "?action=resetbytoken&token=$token&source=sms";

    # Redirect
    header("Location: " . $reset_url);
    exit;
}

#==============================================================================
# HTML
#==============================================================================
?>

<div class="result alert alert-<?php echo get_criticity($result) ?>">
<p><i class="fa <?php echo get_fa_class($result) ?>" aria-hidden="true"></i> <?php echo $messages[$result]; ?></p>
</div>

<?php 
if ( $result == "smscrypttokensrequired" ) {
} elseif ( $result == "smsuserfound" ) {
?>

<div class="alert alert-info">
<form action="#" method="post" class="form-horizontal">
    <div class="form-group">
        <label class="col-sm-4 control-label"><?php echo $messages["userfullname"]; ?></label>
        <div class="col-sm-8">
            <p class="form-control-static"><?php echo $displayname[0]; ?></p>
        </div>
    </div>
    <div class="form-group">
        <label class="col-sm-4 control-label"><?php echo $messages["login"]; ?></label>
        <div class="col-sm-8">
            <p class="form-control-static"><?php echo $login; ?></p>
        </div>
    </div>
    <div class="form-group">
        <label class="col-sm-4 control-label"><?php echo $messages["sms"]; ?></label>
        <div class="col-sm-8">
            <p class="form-control-static"><?php if ($sms_partially_hide_number) echo (substr_replace($sms, '****', 4 , 4)); else echo $sms;?></p>
        </div>
    </div>
    <input type="hidden" name="login" value="<?php echo htmlentities($login) ?>" />
    <input type="hidden" name="sms" value="<?php echo htmlentities($smsnum) ?>" />
    <div class="form-group">
        <div class="col-sm-offset-4 col-sm-8">
            <button type="submit" class="btn btn-success">
                <i class="fa fa-check-square-o"></i> <?php echo $messages['submit']; ?>
            </button>
        </div>
    </div>
</form>
</div>

<?php
} elseif ( $result == "smssent" ) { ?>

<div class="alert alert-info">
<form action="#" method="post" class="form-horizontal">
    <div class="form-group">
        <label for="smstoken" class="col-sm-4 control-label"><?php echo $messages["smstoken"]; ?></label>
        <div class="col-sm-8">
            <div class="input-group">
                <span class="input-group-addon"><i class="fa fa-key"></i></span>
                <input type="text" name="smstoken" id="smstoken" class="form-control" placeholder="<?php echo $messages["smstoken"]; ?>" />
            </div>
        </div>
    </div>
    <input type="hidden" name="token" value=<?php echo htmlentities($token) ?> />
    <input type="hidden" name="login" value=<?php echo htmlentities($login) ?> />
    <div class="form-group">
        <div class="col-sm-offset-4 col-sm-8">
            <button type="submit" class="btn btn-success">
                <i class="fa fa-check-square-o"></i> <?php echo $messages['submit']; ?>
            </button>
        </div>
    </div>
</form>
</div>

<?php } else{

if ( $show_help ) {
    echo "<div class=\"help alert alert-warning\"><p>";
    echo "<i class=\"fa fa-info-circle\"></i> ";
    echo $messages["sendsmshelp"];
    echo "</p></div>\n";
}
?>

<div class="alert alert-info">
<form action="#" method="post" class="form-horizontal">
<?php if ($use_recaptcha) recaptcha_get_conf($recaptcha_theme, $lang); ?>
    <div class="form-group">
        <label for="login" class="col-sm-4 control-label"><?php echo $messages["login"]; ?></label>
        <div class="col-sm-8">
            <div class="input-group">
                <span class="input-group-addon"><i class="fa fa-user"></i></span>
                <input type="text" name="login" id="login" value="<?php echo htmlentities($login) ?>" class="form-control" placeholder="<?php echo $messages["login"]; ?>" />
            </div>
        </div>
    </div>
<?php if ($use_recaptcha) { ?>
    <div class="form-group">
        <div class="col-sm-offset-4 col-sm-8">
<?php echo recaptcha_get_html($recaptcha_publickey, null, $recaptcha_ssl); ?>
        </div>
    </div>
<?php } ?>
    <div class="form-group">
        <div class="col-sm-offset-4 col-sm-8">
            <button type="submit" class="btn btn-success">
                <i class="fa fa-search"></i> <?php echo $messages['getuser']; ?>
            </button>
        </div>
    </div>
</form>
</div>

<?php } ?>
