#!/usr/bin/php5
<?php
/**
 * Market Gnome
 *
 * Copyright (c) 2012-2013, Barney Garrett.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 * * Redistributions of source code must retain the above copyright
 * notice, this list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright
 * notice, this list of conditions and the following disclaimer in
 * the documentation and/or other materials provided with the
 * distribution.
 *
 * * Neither the name of Barney Garrett nor the names of his
 * contributors may be used to endorse or promote products derived
 * from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRIC
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 */

error_reporting(E_ALL ^ E_NOTICE);

$config = parse_ini_file("mg.ini", true);
$ignore_list = explode(',',$config['ignore_list']['list']);

// initialize JAXL object with initial config

require_once './JAXL/jaxl.php';

$client = new JAXL(array(
	'jid' => $config['authentication']['jid'],
	'pass' => $config['authentication']['password'],
	'priv_dir' => './JAXL/.jaxl',
	'auth_type' => 'DIGEST-MD5',
	'resource' => 'Market Gnome',
	'log_level' => JAXL_INFO,
	'log_path' => '/var/log/jaxl.log',
	'strict'=>FALSE
));

$client->manage_subscribe = "mutual";

// callback functions

$client->add_cb('on_auth_success', function() {
	global $client;
	echo "got on_auth_success cb, jid ".$client->full_jid->to_string()."\n";

	// set status
	$client->set_status("available!", "Online", 10);

	// fetch roster list
	$client->get_roster();
});

$client->add_cb('on_auth_failure', function($reason) {
	global $client;
	$client->send_end_stream();
	echo "got on_auth_failure cb with reason $reason\n";
});

$client->add_cb('on_presence_stanza', function($stanza) {
	global $client;
	// do nothing with presence stanza's
});

$client->add_cb('on_chat_message',function($stanza) {
	global $client,$config,$ignore_list;


	//$client->get_roster();
	
	$from=preg_replace('/\/.*$/', '', $stanza->from);
	if((strlen($stanza->body) > 0) && (!in_array($from,$ignore_list))) 
	{
		$pdo = new PDO("mysql:host=".$config['mysql']['host'].";dbname=".$config['mysql']['db_name'], $config['mysql']['user'], $config['mysql']['password']);
		$item_sql = $pdo->prepare('select typeID from invTypes where typeName = :tn');
		$system_sql = $pdo->prepare('select itemID from mapDenormalize where solarSystemID is NULL and typeID = 5 and itemName = :in');
		$region_sql = $pdo->prepare('select itemID from mapDenormalize where regionID is NULL and typeID = 3 and itemName = :in');
		
		// echo back the incoming message
		if (preg_match('/^\?OTR/',$stanza->body))
		{
			$message = "**** Thank you for your request " . $from . " ****\nTurn off OTR you muppet !!!\n";
        }
		else	
		{
			$request = explode ( '$' , $stanza->body);
			$rc = count($request);
			$reqest_type = '';
			$typeID = '';
			$locationID ='';
			$scount = 0;
			$icount = 0;
			$invalid=false;

			switch ($rc)
			{
				case 1:
					$item = trim($request[0]);
					$loc = 'Jita';
					$ival = $item_sql->execute(array(':tn'=>$item));
					$locationName = "Default (Jita)";
					while ($typeID_row = $item_sql->fetch())
					{
						if (empty($typeID))
						{
							$typeID=$typeID_row['typeID'];
						}
						else
							$typeID.=",".$typeID_row['typeID'];
						$icount++;
					}
					if ($icount != 1)
					{
						$message = "**** Thank you for your request " . $from . " ****\nThere was an error with your request Format is Item Name\$System Name\n";
						if ($icount == 0)
						{
							$message .= "The specified Item was not found\n";
						}
						$invalid = true;
					}
					$locationID='30000142';
					$request_type = 'solarsystem';
					Break;
				case 2:
					$item = trim($request[0]);
					$loc = trim($request[1]);
					$ival = $item_sql->execute(array(':tn'=> $item));
					$system_sql->execute(array(':in'=>$loc));
					$locationName = $loc;
					while ($typeID_row = $item_sql->fetch())
					{
						if (empty($typeID))
						{
							$typeID=$typeID_row['typeID'];
						}
						else
							$typeID.=",".$typeID_row['typeID'];
						$icount++;
					}
					while ($systemID_row = $system_sql->fetch())
					{
						$locationID=$systemID_row['itemID'];
						$scount++;
					}
					if (($icount != 1) || ($scount != 1))
					{
						$message = "**** Thank you for your request " . $from . " ****\nThere was an error with your request Format is Item Name\$System Name\n";
						if ($icount == 0)
						{
							$message .= "The specified Item was not found\n";
						}
						if ($scount ==0)
						{
							$message .= "The specified System was not found\nIf you have entered a Region name the format is Item Name\$R\$Region Name\n";
						}
						$invalid = true;
					}
					$request_type = 'solarsystem';
					Break;
				case 3:
					$item = trim($request[0]);
					$loc = trim($request[2]);
					$type = trim($request[1]);
					if (strlen($loc) === 1)
					{
						$message = "**** Thank you for your request " . $from . " ****\nThere was an error with your request Format is Item Name\$R\$System Name\n";
						$message .= "The request type should be the 2nd parameter\n";
						Break;
					}
					switch ($type)
					{
						case 'R':
						case 'r':
							$ival = $item_sql->execute(array(':tn'=>$item));
							$region_sql->execute(array(':in'=>$loc));
							$locationName = "Region - " . $loc;
							while ($typeID_row = $item_sql->fetch())
							{
								if (empty($typeID))
								{
									$typeID=$typeID_row['typeID'];
								}
								else
									$typeID.=",".$typeID_row['typeID'];
								$icount++;
							}
							while ($regionID_row = $region_sql->fetch())
							{
								$locationID=$regionID_row['itemID'];
								$scount++;
							}
							if (($icount != 1) || ($scount != 1))
							{
								$message = "**** Thank you for your request " . $from . " ****\nThere was an error with your request Format is Item Name\$System Name\n";
								if ($icount == 0)
								{
									$message .= "The specified Item was not found\n";
								}
								if ($scount ==0)
								{
									$message .= "The specified Region was not found\n";
								}
								$invalid=true;
							}
							$request_type = 'region';
							Break;
						default:

							$message = "**** Thank you for your request " . $from . " ****\nThere was an error with your request Format is Item Name\$System Name\n";
							$message .= "Sorry that request type was not understood\n";
							Break;
					}
					Break;					
				default:
					$message = "**** Thank you for your request " . $from . " ****\nThere was an error with your request Format is Item Name\$System Name\n";
					$message .= "Sorry the wrong number of parameters was supplied\n";
					Break;
			}

			if ( ($rc >= 1) && ($rc <=3) && (strlen($loc) != 1) && ($invalid == false))
			{
				$return = '';
				$ch = curl_init();
				$char_name = rawurlencode($config['eve-marketdata']['char_name']);
				$curl_url = "http://api.eve-marketdata.com/api/item_prices2.txt?char_name=".$char_name."&type_ids=" . $typeID ."&".$request_type."_ids=" .$locationID ."&buysell=s&minmax=min";
				curl_setopt($ch, CURLOPT_URL, $curl_url);
				curl_setopt($ch, CURLOPT_HEADER, 0);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				$return = curl_exec($ch);
				curl_close($ch);
				preg_match('/[sb]\s+\d+\s+\d+\s+(\d+\.*\d*)\s+(.*)$/m',$return,$matches);
				$price = $matches[1];
				$price = number_format(floatval($price),2,'.',',');
				$updated = $matches[2];
				$from=preg_replace('/@.*$/','',$from);
				$message = "**** Thank you for your request " . $from . " ****\nItem = " . $item . "\nLocation = " . $locationName . "\nPrice = " . $price . "\nLast updated : " . $updated ."\n";
			}
		}
				
		$msg = new XMPPMsg(array('type'=>'chat', 'to'=>$stanza->from, 'from'=>$stanza->to), $message);
		$client->send($msg);
		$pdo = null;
	}
});


$client->add_cb('on_disconnect', function() {
	echo "got on_disconnect cb\n";
});

// finally start configured xmpp stream

$client->start();
?>
