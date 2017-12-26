<?php
# Copyright (C) 2017 @Thibautg16
# This file is part of NetatmoThermostatApp <https://github.com/Thibautg16/NetatmoThermostatApp>.
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with This program. If not, see <http://www.gnu.org/licenses/>.

$MODE_NETATMO = 'netatmo'; // Dans ce mode, on met une consigne temporaire
$MODE_EEDOMUS = 'eedomus'; // Dans ce mode, on maintient la température de consigne
$CACHE_DURATION = 2; // minutes
$api_url = 'https://api.netatmo.net';
$is_action = false; // Action ou mode capteur ?
$code = getArg('oauth_code');
$device_id = $_GET['device_id'];
$module_id = $_GET['module_id']; 
$prev_code = loadVariable('code');

function sdk_netatmo_html($extension_module)
{
	$ret = '';

	if(sizeof($extension_module) > 0)
	{
		$ret .= '<br>';
		$ret .= '<br>';
		$ret .= '<br>';
		$ret .= "Voici la liste des modules dont vous pouvez modifier la température de consigne:";
		$ret .= '<br>';
		$ret .= '<dl>';
	}

	for ($i = 0; $i < sizeof($extension_module); $i++)
	{
		$id = $extension_module[$i]['id'];

		// attention aux "&" qui doivent être convertis
		//$ret .= '<name>'.htmlspecialchars($extension_module[$i]['name']).'</name>';
		$ret .= '<dt>'.$extension_module[$i]['name'].'</dt>';
		$ret .= '<dd>Adresse MAC du thermostat Netatmo : <input onclick="this.select();" type="text" size="17" readonly="readonly" value="'.$id.'"</dd>';
		$ret .= '<dd>Adresse MAC du relais Netatmo : <input onclick="this.select();" type="text" size="17" readonly="readonly" value="'.$extension_module[$i]['device_id'].'"</dd>';
	}

	if(sizeof($extension_module) > 0)
	{
		$ret .= '</dl>';
	}

	return $ret;
}

function sdk_netatmo_get_zone_temperature($zone_id, $zones_array)
{
	$ret = '';

	for ($j = 0; $j < sizeof($zones_array); $j++)
	{
		if($zones_array[$j]['id'] == $zone_id)
		{
			$zone_temperature = $zones_array[$j]['temp'];
			if (isset($zone_temperature))
			{
				$ret .= $zone_temperature;
			}
			break;
		}
	}

	return $ret;
}

function sdk_netatmo_set_temperature($temperature, $mode, $delay = 1)
{
	$endtime;
	if($mode == $GLOBALS['MODE_EEDOMUS'])
	{
		$endtime = time() + 12 /*heures*/ * 3600;
	}
	else if($mode == $GLOBALS['MODE_NETATMO'])
	{
		$endtime = time() + $delay /*heures*/ * 3600;
	}
	$url = $GLOBALS['api_url'].'/api/setthermpoint?access_token='.$GLOBALS['access_token'].'&device_id='.$GLOBALS['device_id'].'&module_id='.$GLOBALS['module_id'].'&setpoint_mode=manual&setpoint_temp='.$temperature.'&setpoint_endtime='.$endtime;
	
	$result = httpQuery($url, 'GET', NULL, NULL, NULL, false);
	//var_dump($url, $result); die();
}

$setpoint_mode = $_GET['setpoint_mode'];
$setpoint_temp = $_GET['setpoint_temperature'];
if ($setpoint_mode != '' || $setpoint_temp != '')
{
	$is_action = true;
}

$last_xml_success = loadVariable('last_xml_success');
$time_from_last = time() - $last_xml_success;
$time_from_last = $time_from_last / 60;
if (!$is_action && $_GET['mode'] != 'verify' && $time_from_last < $CACHE_DURATION)
{
	sdk_header('text/xml');
	$cached_xml = loadVariable('cached_xml');
	echo $cached_xml;
	die();
}

if (strlen($prev_code) > 1 && $code == $prev_code)
{
  // on reprend le dernier refresh_token seulement s'il correspond au même code
	$refresh_token = loadVariable('refresh_token');
	$expire_time = loadVariable('expire_time');
	// s'il n'a pas expiré, on peut reprendre l'access_token
  if (time() < $expire_time)
  {
    $access_token = loadVariable('access_token');
  }
}

// on a déjà un token d'accés non expiré pour le code demandée
if ($access_token == '')
{
	if (strlen($refresh_token) > 1)
	{
		// on peut juste rafraichir le token
		$grant_type = 'refresh_token';
		$postdata = 'grant_type='.$grant_type.'&refresh_token='.$refresh_token;
	}
	else
	{
		// 1ère utilisation aprés obtention du code
		$grant_type = 'authorization_code';
		$redirect_uri = 'https://secure.eedomus.com/sdk/plugins/netatmo_thermostat/callback.php';
		$scope = 'read_thermostat write_thermostat';
		$postdata = 'grant_type='.$grant_type.'&code='.$code.'&redirect_uri='.$redirect_uri.'&scope='.$scope;
	}

	$response = httpQuery($api_url.'/oauth2/token', 'POST', $postdata, 'netatmo_thermostat_oauth');
	$params = sdk_json_decode($response);

	if ($params['error'] != '')
	{
		die("Erreur lors de l'authentification: <b>".$params['error'].'</b> (grant_type = '.$grant_type.')');
	}

	// on sauvegarde l'access_token et le refresh_token pour les authentifications suivantes
	if (isset($params['refresh_token']))
	{
		$access_token = $params['access_token'];
		saveVariable('access_token', $access_token);
		saveVariable('refresh_token', $params['refresh_token']);
		saveVariable('expire_time', time()+$params['expires_in']);
		saveVariable('code', $code);
	}
	else if ($access_token == '')
	{
		die("Erreur lors de l'authentification");
	}
}

if ($_GET['mode'] == 'verify')
{
	?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
  <head>
  <title>eedomus</title>
  <style type="text/css">
  
  body,td,th {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 14px;
  }
  </style>
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
  </head><?
  echo '<br>';
	echo "Votre code d'authentification Netatmo est : ".'<input onclick="this.select();" type="text" size="40" readonly="readonly" value="'.$code.'" />';
	echo '<br>';
	echo '<br>';
	echo "Vous pouvez le copier/coller dans le paramètrage de votre périphérique eedomus.";

	$url_devices = $api_url.'/api/getthermostatsdata?app_type=app_thermostat&access_token='.$access_token;
	$result_devices = httpQuery($url_devices);
	$json_devices = sdk_json_decode($result_devices);

	if ($json_devices['error']['code'] == 2 /*invalid access token*/)
	{
	  // on force l'expiration pour la fois suivante
	  saveVariable('expire_time', 0);
	}

	if ($json_devices['error'] != '')
	{
	  die("Erreur lors de la lecture des devices: <b>".$json_devices['error']['message'].'</b>');
	}
	
	//var_dump($json_devices['body']['devices'][0]["modules"][0]); die();

	// Modules d'extension
	for ($i = 0; $i < sizeof($json_devices['body']['devices'][0]["modules"]); $i++)
	{
		$extension_module[$i]['id'] = $json_devices['body']['devices'][0]["modules"][$i]['_id'];
		$extension_module[$i]['device_id'] = $json_devices['body']['devices'][0]['_id'];
		$extension_module[$i]['name'] = $json_devices['body']['devices'][0]["modules"][$i]['module_name'];
	}

	echo sdk_netatmo_html($extension_module);
	die();
}

if($device_id == '' || $module_id == '')
{
	sdk_header('text/xml');
	$xml = '<?xml version="1.0" encoding="utf8" ?>';
	$xml .= '<netatmo>';
	$xml .= '<status>';
	$xml .= 'device_id or module_id missing';
	$xml .= '</status>';
	$xml .= '</netatmo>';
	echo $xml;
	die();
}

// Les critères sont réunis pour demander d'effectuer une action
if ($is_action)
{
	// Valeurs possibles pour le mode
	$setpoint_mode_valid_values = array('program', 'away', 'hg', 'manual', 'off', 'max');

	// XML de sortie
	sdk_header('text/xml');
	$xml = '<?xml version="1.0" encoding="utf8" ?>';
	$xml .= '<netatmo>';
	$xml .= '<status>';

	// On va passer en mode manuel et forcer une température
	if($setpoint_temp != '')
	{
		$mode;
		$maintain_setpoint = $_GET['maintain_setpoint'];
		if($maintain_setpoint == "always")
		{
			$mode = $MODE_EEDOMUS;
			saveVariable('maintain_mode', $MODE_EEDOMUS);
		}
		else
		{
			$mode = $MODE_NETATMO;
			saveVariable('maintain_mode', $MODE_NETATMO);
		}
		sdk_netatmo_set_temperature($setpoint_temp, $mode, abs($maintain_setpoint));
		$xml .= 'ok';
	}
	// On va seulement changer de mode sans modifier la température
	else if(in_array($setpoint_mode, $setpoint_mode_valid_values))
	{
		$url = $api_url.'/api/setthermpoint?access_token='.$access_token.'&device_id='.$device_id.'&module_id='.$module_id.'&setpoint_mode='.$setpoint_mode;
		if ($setpoint_mode == 'max')
		{
			$endtime = time() + 12 /*heures*/ * 3600;
			$url .= '&setpoint_endtime='.$endtime;
		}
		httpQuery($url, 'GET', NULL, NULL, NULL, false);
		$xml .= 'ok';
	}
	else
	{
		$xml .= 'ko';
	}

	$xml .= '</status>';
	$xml .= '</netatmo>';
	echo $xml;
}
// On effectue une lecture des données
else
{
	$url_devices = $api_url.'/api/getthermostatsdata?access_token='.$access_token.'&device_id='.$device_id.'&module_id='.$module_id;
	$result_devices = httpQuery($url_devices);
  //var_dump($url_devices, $result_devices);
	// gestion offline pour débug
	//saveVariable('result_devices', $result_devices); die();
	//$result_devices = loadVariable('result_devices');

	$json_devices = sdk_json_decode($result_devices);

	if ($json_devices['error']['code'] == 2 /*invalid access token*/)
	{
		// on force l'expiration pour la fois suivante
		saveVariable('expire_time', 0);
	}

	if ($json_devices['error'] != '')
	{
		die("Erreur lors de la lecture des devices #2: <b>".$json_devices['error']['message'].'</b>');
	}
  // Modules d'extension
	for ($i = 0; $i < sizeof($json_devices['body']['devices'][0]["modules"]); $i++)
	{
		$current_module_id = $json_devices['body']['devices'][0]["modules"][$i]['_id'];
    if ($current_module_id == $module_id)
    {
      $setpoint_mode = $json_devices['body']['devices'][0]["modules"][$i]['setpoint']['setpoint_mode'];

	  // En cas de consigne manuel via l'application Netatmo, la consigne est dans "['setpoint']['setpoint_temp']"
	  if($setpoint_mode == 'manual'){
		  $setpoint_temperature = $json_devices['body']['devices'][0]["modules"][$i]['setpoint']['setpoint_temp'];
	  }
	  else {
		  $setpoint_temperature = $json_devices['body']['devices'][0]["modules"][$i]['measured']['setpoint_temp'];
	  }
	  
      $temperature = $json_devices['body']['devices'][0]["modules"][$i]['measured']['temperature'];
      $battery_percent = $json_devices['body']['devices'][0]["modules"][$i]['battery_percent'];
      $rf_status = $json_devices['body']['devices'][0]["modules"][$i]['rf_status'];
      $relay_command = $json_devices['body']['devices'][0]["modules"][$i]['therm_relay_cmd'];
    }

	// relay information
	$relay_wifi_status = $json_devices['body']['devices'][0]["wifi_status"];
  }

	// XML de sortie
	// permet d'avoir une mise en forme plus lisible dans un browser
	sdk_header('text/xml');
	// Contenu du XML
	$cached_xml = '<?xml version="1.0" encoding="utf8" ?>';
	$cached_xml .= '<netatmo>';
	$cached_xml .= '<cached>0</cached>';
	
	if (isset($setpoint_mode))
	{
		$cached_xml .= '<setpoint_mode>'.$setpoint_mode.'</setpoint_mode>';
	}
	if (isset($setpoint_temperature) && $setpoint_mode == 'manual')
	{
		// Temperature manuellement imposee
		$cached_xml .= '<setpoint_temperature>'.$setpoint_temperature.'</setpoint_temperature>';

		// Verification: besoin de l'imposer à nouveau ?
		// Oui si on est en mode eedomus et proche du temps imparti
		$maintain_mode = loadVariable('maintain_mode');
		if($maintain_mode != $MODE_NETATMO)
		{
			$now = time();
			$setpoint_endtime = $json_devices['body']['devices'][0]['modules'][0]['setpoint']['setpoint_endtime'];
			if($setpoint_endtime - $now < 3600)
			{
				sdk_netatmo_set_temperature($setpoint_temperature, $MODE_EEDOMUS);
			}
		}
	}
	else if($setpoint_mode == 'hg')
	{	 

		// Trouver la temperature hors gel
		$zone_id = 3;
		$zones_array = $json_devices['body']['devices'][0]['modules'][0]['therm_program_list'][0]['zones'];
		$cached_xml .= '<setpoint_temperature>'.sdk_netatmo_get_zone_temperature($zone_id, $zones_array).'</setpoint_temperature>';
	}
	else if($setpoint_mode == 'away')
	{

		// Trouver la temperature en cas d'absence
		$zone_id = 2;
		$zones_array = $json_devices['body']['devices'][0]['modules'][0]['therm_program_list'][0]['zones'];
		$cached_xml .= '<setpoint_temperature>'.sdk_netatmo_get_zone_temperature($zone_id, $zones_array).'</setpoint_temperature>';
	}
	else if($setpoint_mode == 'max')
	{
		// Trouver la temperature en cas d'absence
		$cached_xml .= '<setpoint_temperature>max</setpoint_temperature>';
	}
	else
	{
		// Trouver la temperature programmee
		$monday = strtotime("previous monday", strtotime("tomorrow"));
		$now = time();
		$week_offset = $now - $monday;
		$week_offset = $week_offset / 60;
		
		// Parcourt pour repérer la zone actuelle de programmation dans la semaine
		for ($i = 0; $i < sizeof($json_devices['body']['devices'][0]['modules'][0]['therm_program_list'][0]['timetable']); $i++)
		{
			$timetable_offset = $json_devices['body']['devices'][0]['modules'][0]['therm_program_list'][0]['timetable'][$i]['m_offset'];
			if($week_offset < $timetable_offset && $i > 0)
			{
				$zone_id = $json_devices['body']['devices'][0]['modules'][0]['therm_program_list'][0]['timetable'][$i-1]['id'];
				$zones_array = $json_devices['body']['devices'][0]['modules'][0]['therm_program_list'][0]['zones'];
				$cached_xml .= '<setpoint_temperature>'.sdk_netatmo_get_zone_temperature($zone_id, $zones_array).'</setpoint_temperature>';
				break;
			}
			else if(sizeof($json_devices['body']['devices'][0]['modules'][0]['therm_program_list'][0]['timetable']) - 1 == $i)
			{
				$zone_id = $json_devices['body']['devices'][0]['modules'][0]['therm_program_list'][0]['timetable'][$i]['id'];
				$zones_array = $json_devices['body']['devices'][0]['modules'][0]['therm_program_list'][0]['zones'];
				$cached_xml .= '<setpoint_temperature>'.sdk_netatmo_get_zone_temperature($zone_id, $zones_array).'</setpoint_temperature>';
			}
		}
	}
	
	if (isset($temperature))
	{
    $allow_cache = true;
		$cached_xml .= '<temperature>'.$temperature.'</temperature>';
	}
	else
	{
    $allow_cache = false;
	}
	
	if (isset($battery_percent)){
		$cached_xml .= '<battery_percent>'.$battery_percent.'</battery_percent>';
	}	
	if (isset($relay_wifi_status)){
		if ($relay_wifi_status <= 66){
			$wifi_status=0;
		}
		else if($relay_wifi_status > 66 && $relay_wifi_status <= 76){
			$wifi_status=1;
		}
		else if($relay_wifi_status > 76){
			$wifi_status=2;
		}
		$cached_xml .= '<relay_wifi_status>'.$wifi_status.'</relay_wifi_status>';
	}
	if (isset($rf_status)){
		if ($rf_status <= 70){
			$rf_status_xml=0;
		}
		else if($rf_status > 70 && $rf_status <= 80){
			$rf_status_xml=1;
		}
		else if($rf_status > 80){
			$rf_status_xml=2;
		}
		$cached_xml .= '<rf_status>'.$rf_status_xml.'</rf_status>';
	}	
	if (isset($relay_command))
	{
		if($relay_command == 100)
		{
			$cached_xml .= '<boiler>100</boiler>';
		}
		else if($relay_command == 0)
		{
			$cached_xml .= '<boiler>0</boiler>';
		}
    		else if($relay_command == 200) 
		{
			$cached_xml .= '<boiler>200</boiler>'; // mode confort
		}
	}
	
	//$cached_xml .= '<token>'.$access_token.'</token>';
	$cached_xml .= '</netatmo>';

	echo $cached_xml;
	$cached_xml = str_replace('<cached>0</cached>', '<cached>1</cached>', $cached_xml);
	if ($allow_cache)
	{
    saveVariable('cached_xml', $cached_xml);
    saveVariable('last_xml_success', time());
	}
}
?>
