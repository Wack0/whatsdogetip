<?php

date_default_timezone_set('UTC');
require_once('whatsprot.class.php');
include("Dogecoin.php");
include("jsonRPCClient.php");

$userPhone        = ''; 
$userIdentity     = '';
$userName         = 'Dogecoin Tip Bot'; 
$password         = ''; 
$debug            = false;

$dogetip = new jsonRPCClient(""); // put your dogecoind api info here.

$w = new WhatsProt($userPhone, $userIdentity, $userName, $debug);

if (!file_exists("whatstip.db")) {
	$db = new SQLite3("whatstip.db");
	$db->exec("CREATE TABLE IF NOT EXISTS users(id integer primary key autoincrement, username text, accepted tinyint not null default 0, noverify tinyint not null default 0, blocked tinyint not null default 0)");
	$db->exec("CREATE TABLE IF NOT EXISTS txes(id integer primary key autoincrement, withdraw tinyint not null default 0, newuser tinyint not null default 0, sender text, receiver text, amount text, time unsigned bigint)");
} else $db = new SQLite3("whatstip.db");

define("ACCOUNT_PREFIX","whatsapp_");
define("ADMIN_NUMBER","");

$w->Connect();
$w->LoginWithPassword($password);

while (true) {
	if ((bool)($db->querySingle("SELECT count(*) from users where accepted=0"))) {
		$res = $db->query("SELECT username FROM users WHERE accepted=0");
		while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
			if ((bool)($db->querySingle("SELECT count(*) from txes where receiver='".$row['username']."' and time<=".((time() - 2*24*60*60))))) {
				$res2 = $db->query("SELECT id,sender,amount from txes where receiver='".$row['username']."' and time<=".((time() - 2*24*60*60)));
				$killme = false;
				while ($row2 = $res2->fetchArray(SQLITE3_ASSOC)) {
					// reverse the transaction
					try {
					$dogetip->move(ACCOUNT_PREFIX.$row['username'],ACCOUNT_PREFIX.$row2['sender'],$row2['amount']);
					} catch (Exception $e) { echo "Moving an unclaimed tip from ".$row['username']." back to ".$row2['sender']." (amount : ".$row2['amount']." ) failed.\n"; } // I know.. I know..
					$db->query("DELETE FROM txes where id=".$row2['id']);
				}
				try {
					$addy = json_decode(json_encode($dogetip->getaddressesbyaccount(ACCOUNT_PREFIX.$row['username'])),true);
				} catch (Exception $e) { echo "Getting accounts failed\n"; }
				foreach ($addy as $a) {
					try {
						$dogetip->setaccount($a,ACCOUNT_PREFIX."unclaimedtips");
					} catch (Exception $e) { echo "Changing address of ".$row['username']." to unclaimed tips failed..\n"; }
				}
				$db->query("DELETE FROM users where username='".$row['username']."'");
			}
		}
	}
    $w->PollMessages();
	if ($w->socket == null) {
		$w->Connect();
		$w->LoginWithPassword($password);
		$w->PollMessages();
	}
    $msgs = $w->GetMessages();
    foreach ($msgs as $m) {
		$time = date("m/d/Y H:i", $m->attributeHash['t']);
		$from = str_replace("@s.whatsapp.net", "", $m->attributeHash['from']);
		$name = "(unknown)";
		$body = "";

		foreach ($m->children as $child) {
			if ($child->tag == "body") {
				$body = $child->data;
			}
			else if ($child->tag == "notify") {
				if (isset($child->attributeHash) && isset($child->attributeHash['name'])) {	
					$name = $child->attributeHash['name'];
				}
			}
		}

		if (!empty($body)) {
			$msg = new stdClass;
			$msg->from = $from;
			$msg->name = $name;
			$msg->body = explode(" ",$body);
			$msg->time = $time;
			$dtp = new DogeTipParser($w,$db,$msg);
			echo "Parsing: (".$time.") ".$from.": ".$body."\n";
			$dtp->parse();
		}
	}
}

class DogeTipParser {
	var $w;
	var $db;
	var $msg;
	
	function __construct($w,$db,$msg) {
		$this->w = $w;
		$this->db = $db;
		$this->msg = $msg;
	}
	
	function parse() {
		// is this user blocked?
		if ((bool)($this->db->querySingle("select blocked from users where username='".$this->db->escapeString($this->msg->from)."'"))) {
			// did he ask to unblock ?
			if ($this->msg->body[0] == "unblock") {
				$this->msgUnblock();
			}
			return;
		}
		switch (strtolower($this->msg->body[0])) {
			case "info":
			case "'info'":
				$this->msgInfo();
				break;
			case "tipcreate":
				$this->msgTipCreate();
				break;
			case "withdraw":
				$this->msgWithdraw();
				break;
			case "tip":
				$this->msgTip();
				break;
			case "history":
				$this->msgHistory();
				break;
			case "accept":
			case "'accept'":
				$this->msgAccept();
				break;
			case "block":
			case "'block'":
				$this->msgBlock();
				break;
			case "noverify":
			case "'noverify'":
				$this->msgNoVerify();
				break;
			case "verify":
			case "'verify'":
				$this->msgVerify();
				break;
			case "help":
				$this->msgHelp();
				break;
		}
		
		if ($this->msg->body == array()) return;
		if ($this->msg->body[0] == "Raylee!") $this->msgEgg();
		
		if ($this->msg->from == ADMIN_NUMBER) {
			if ($this->msg->body == array()) return;
			switch (strtolower($this->msg->body[0])) {
				case "exit":
					$this->msgExit();
					break;
				case "query":
					$this->msgQuery();
					break;
				case "querys":
					$this->msgQueryS();
					break;
			}
		}
	}
	
	function message() {
		$argc = func_num_args();
		if ($argc < 1) return;
		if ($argc == 1) return $this->w->sendMessage($this->msg->from,func_get_arg(0));
		return $this->w->sendMessage(func_get_arg(0),func_get_arg(1));
	}
	
	function msgInfo() {
		global $dogetip;
		try {
			$accounts = json_decode(json_encode($dogetip->listaccounts()),true);
		} catch (Exception $e) {
			$this->message("An internal error occured. Try again later.");
			return;
		}
		$id = $this->msg->from;
		if (!array_key_exists(ACCOUNT_PREFIX.$id,$accounts)) {
			// no account.
			$this->message("You do not have a tipping account. To create one, message me with: tipcreate");
			return;
		}
		$amount = $accounts[ACCOUNT_PREFIX.$id];
		try {
			$tipaddress = $dogetip->getaddressesbyaccount(ACCOUNT_PREFIX.$id);
		} catch (Exception $e) {
			$this->message("An internal error occured. Try again later.");
			return;
		}
		$tipaddress = $tipaddress[0];
		$this->message("You have ".$amount." DOGE in your tipping account. To get more, send dogecoins to: ".$tipaddress."\n".
		"To tip someone dogecoins, message me with: tip number dogecoins (where number is the number of someone on whatsapp, and dogecoins is the amount of dogecoins you wish to tip them)\n".
		"To withdraw dogecoins from your tipping account, message me with: withdraw address amount (where amount can be 'all' without the apostrophes to withdraw all of your dogecoins)\n".
		"You can also donate to the dogec0in waterbowl directly from whatsapp, just use 'waterbowl' without the apostrophes in place of a dogecoin address.");
	}
	
	function msgTipCreate() {
		global $dogetip;
		$id = $this->msg->from;
		try {
			$accounts = json_decode(json_encode($dogetip->listaccounts()),true);
		} catch (Exception $e) {
			$this->message("An internal error occured. Try again later.");
			return;
		}
		if (array_key_exists(ACCOUNT_PREFIX.$id,$accounts)) {
			// this user has an account already.
			$this->message("You already have a tipping account. To view information about it, message me with: info");
			return;
		}
		// create the tipping account
		try {
			$tipaddress = $dogetip->getaccountaddress(ACCOUNT_PREFIX.$id);
		} catch (Exception $e) {
			$this->message("An internal error occured. Try again later.");
			return;
		}
		$this->db->query("INSERT INTO users (username) values ('".$this->db->escapeString($id)."')");
		$this->message("Your new tipping account has been created. To deposit to your tipping account, send dogecoins to the address: ".$tipaddress);
		return;
	}
	
	function msgWithdraw() {
		global $dogetip;
		array_shift($this->msg->body);
		$msg = $this->msg->body;
		if (count($msg) < 2) {
			$this->message("Usage: withdraw address amount");
			return;
		}
		if (strtolower($msg[0]) == "waterbowl")
			$msg[0] = "DCE55iF3wTpAZjqdtddSvaaA2PgixJjSUG";
		if (!Dogecoin::checkAddress($msg[0])) {
			$this->message("Usage: withdraw address amount");
			return;
		}
		$id = $this->msg->from;
		try {
			$accounts = json_decode(json_encode($dogetip->listaccounts()),true);
		} catch (Exception $e) {
			$this->message("An internal error occured. Try again later.");
			return;
		}
		if (!array_key_exists(ACCOUNT_PREFIX.$id,$accounts)) {
			// no account.
			$this->message("You do not have a tipping account. To create one, message me with: tipcreate");
			return;
		}
		$amount = $accounts[ACCOUNT_PREFIX.$id];
		if (strtolower($msg[1]) == "all")
			$msg[1] = $amount;
		else
			$msg[1] = (float)filter_var($msg[1],FILTER_VALIDATE_FLOAT);
		if ($msg[1] < 1) {
			$this->message("Usage: withdraw address amount");
			return;
		}
		if ($amount < $msg[1]) {
			$this->message("You only have ".$amount." in your tipping account.");
			return;
		}
		try {
			$dogetip->sendfrom(ACCOUNT_PREFIX.$id,$msg[0],$msg[1]);
		} catch (Exception $e) {
			$this->message("An internal error occured. Try again later.");
			return;
		}
		// document in DB.
		$this->db->query("INSERT INTO txes (withdraw,sender,receiver,amount,time) values (1,'".$this->db->escapeString($this->msg->from)."','".$this->db->escapeString($msg[0])."','".$msg[1]."',".time().")");
		if ($this->msg->name == "(unknown)") $this->msg->name = $this->msg->from;
		$this->message(base64_decode("w7jCsA==")."wow much withdrawn".base64_decode("wrDDuA==").": ".$this->msg->from." -> ".$msg[0]." __ ".base64_decode("w5A=").$msg[1]);
	}
	
	function msgTip() {
		global $dogetip;
		array_shift($this->msg->body);
		$msg = $this->msg->body;
		if (count($msg) < 2) {
			$this->message("Usage: tip number dogecoins");
			return;
		}
		$msg[1] = (float)filter_var($msg[1],FILTER_VALIDATE_FLOAT);
		if ($msg[1] < 1) {
			$this->message("Usage: tip number dogecoins");
			return;
		}
		if (count($msg) > 2) {
			$this->message("Usage: tip number dogecoins");
			$this->message("Perhaps you didn't format the number correctly. It must have no spaces, dashes, or brackets in it, and must be the full number with country code. For example, +11111111111 or +447123123456 are right, and +1 (111) 111-1111 or +44071234 123456 are wrong.");
			return;
		}
		if ($msg[1] < 5) {
			$this->message("The minimum tipping amount is 5 DOGE.");
			return;
		}
		// remove "+" or "00" from the front of the #
		if (substr($msg[0],0,1) == "+")
			$msg[0] = substr($msg[0],1);
		elseif (substr($msg[0],0,2) == "00")
			$msg[0] = substr($msg[0],2);
		// is this us?
		if ($msg[0] == $this->msg->from) {
			$this->message("You're trying to tip yourself!");
			return;
		}
		// does this user exist?
		$exists = (bool)($this->db->querySingle("SELECT count(*) from users where username='".$this->db->escapeString($msg[0])."'"));
		if ($exists) {
			// did they request a block?
			$res = $this->db->query("SELECT * from users where username='".$this->db->escapeString($msg[0])."'");
			$tipping = $res->fetchArray(SQLITE3_ASSOC);
			if ($tipping['blocked']) {
				$this->message("This individual has requested that they be blocked from this tipbot. Obviously they don't like having dogecoins showered upon them.. :(");
				return;
			}
		} else $tipping = array('id'=>'','username'=>'','noverify'=>1,'blocked'=>1);
		// now let's check to see if this user has an account, and if so, what balance?
		$tipperid = $this->msg->from;
		try {
			$accounts = json_decode(json_encode($dogetip->listaccounts()),true);
		} catch (Exception $e) {
			$this->message("An internal error occured. Try again later.");
			return;
		}
		if (!array_key_exists(ACCOUNT_PREFIX.$tipperid,$accounts)) {
			// no account.
			$this->message("You do not have a tipping account. You must create one first: /msg wowsuchdoge tipcreate");
			return;
		} else if ($accounts[ACCOUNT_PREFIX.$tipperid] < $msg[1]) {
			// not enough balance
			try {
				$tipaddress = $dogetip->getaddressesbyaccount(ACCOUNT_PREFIX.$tipperid);
			} catch (Exception $e) {
				$this->message("An internal error occured. Try again later.");
				return;
			}
			$tipaddress = $tipaddress[0];
			$this->message("You only have ".$accounts[ACCOUNT_PREFIX.$tipperid]." in your tipping account. Your tipping account address is: ".$tipaddress);
			return;
		}
		// does the user being tipped have an account? if not, create one, and alert them now if possible about it, or later if not possible.
		$id = $msg[0];
		if (!$exists) {
			// no account. let's create one.
			// first though, are we actually numeric?
			if (!is_numeric($id)) {
				// remove any spaces, dashes or brackets then try again
				$id = str_replace(" ","",str_replace("(","",str_replace(")","",str_replace("-","",$id))));
				if (!is_numeric($id))
					$this->message("You are not trying to tip a whatsapp number!");
				else {
					// try again.
					$msg[0] = $id;
					$this->msg->body = $msg;
					array_unshift($this->msg->body,"tip");
					$this->msgTip();
				}
				return;
			}
			try {
				$newaddy = $dogetip->getaccountaddress(ACCOUNT_PREFIX.$id);
			} catch (Exception $e) {
				$this->message("An internal error occured. Try again later.");
				return;
			}
			$this->db->query("INSERT INTO users (username) values ('".$this->db->escapeString($id)."')");
			$this->message($id,"You've got Dogecoin! ".$tipperid." sent you a ".base64_decode("w5A=").$msg[1]." tip. (If you don't see it yet, wait for it to confirm.) You can now send others tips with this, or withdraw it (message me and say 'info' without the apostrophes). Your tipping account address is ".$newaddy." - do NOT use it as your wallet!\n".
			"Please note: because you have not been tipped before, you MUST accept the tip, otherwise it will be sent back in 2 days! To accept, message me with 'accept' without the apostrophes.\n".
			"If you want to learn more about Dogecoin, visit http://www.howtodoge.com/ - If you don't want to get messages every time you get tipped, message me and say 'noverify' without the apostrophes - If you don't want to get tipped or use me at all, message me and say 'block'");
		} elseif (!$tipping['noverify']) {
			try {
				$newaddy = $dogetip->getaddressesbyaccount(ACCOUNT_PREFIX.$id);
			} catch (Exception $e) {
				$this->message("An internal error occured. Try again later.");
				return;
			}
			$newaddy = $newaddy[0];
			$this->message($id,"You've got Dogecoin! ".$tipperid." sent you a ".base64_decode("w5A=").$msg[1]." tip. (If you don't see it yet, wait for it to confirm.) You can now send others tips with this, or withdraw it (message me and say 'info' without the apostrophes). Your tipping account address is ".$newaddy." - do NOT use it as your wallet!");
		}
		// now actually transfer the tip!
		try {
			$dogetip->move(ACCOUNT_PREFIX.$tipperid,ACCOUNT_PREFIX.$id,$msg[1]);
		} catch (Exception $e) {
			$irc->message(SMARTIRC_TYPE_NOTICE,$data->nick,"An internal error occured. Try again later.");
			return;
		}
		// insert into db.
		$this->db->query("INSERT INTO txes(newuser,sender,receiver,amount,time) values (".((int)(!$exists)).",'".$this->db->escapeString($tipperid)."','".$this->db->escapeString($id)."','".$this->db->escapeString($msg[1])."',".time().")");
		$this->message(base64_decode("w7jCsA==")."wow such verification".base64_decode("wrDDuA==").": ".$tipperid." -> ".$id." ".base64_decode("w5A=").$msg[1]." [ message me with: info ]");
	}
	
	function msgHistory() {
		array_shift($this->msg->body);
		$msg = $this->msg->body;
		if ($msg == array()) $limit = 10;
		else $limit = (int)filter_var($msg[0],FILTER_VALIDATE_INT);
		if (($limit < 1) || ($limit > 100)) {
			$this->message("Usage: history number_of_transactions_to_show (up to 100, default 10)");
			return;
		}
		$msgfrom = $this->db->escapeString($this->msg->from);
		$res = $this->db->query("select * from txes where sender='".$msgfrom."' or receiver='".$msgfrom."' order by id desc limit ".$limit);
		
		$m = "";
		while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
			if ($row['sender'] == $this->msg->from) $row['sender'] = "you";
			if ($row['receiver'] == $this->msg->from) $row['receiver'] = "you";
			if ($row['withdraw']) $row['withdraw'] = "Withdraw: ";
			else $row['withdraw'] = "Tip: ";
			$m .= $row['withdraw'].base64_decode("w5A=").$row['amount']." from ".$row['sender']." to ".$row['receiver']." on ".date('r',$row['time'])."\n";
		}
		$this->message($m);
	}
	
	function msgAccept() {
		$exists = (bool)($this->db->querySingle("SELECT count(*) from users where username='".$this->db->escapeString($this->msg->from)."'"));
		if (!$exists) {
			$this->message("You do not have a tipbot account. To create one, message me with the text: tipcreate");
			return;
		}
		$this->db->query("UPDATE users set accepted=1 where username='".$this->db->escapeString($this->msg->from)."'");
		$this->message("You have now been accepted. Enjoy tipping Dogecoin!");
	}
	
	function msgBlock() {
		$exists = (bool)($this->db->querySingle("SELECT count(*) from users where username='".$this->db->escapeString($this->msg->from)."'"));
		if (!$exists) {
			$this->message("You do not have a tipbot account. To create one, message me with the text: tipcreate");
			return;
		}
		// reverse transactions if we never accepted, otherwise leave the tips in stasis.
		$res = $this->db->query("SELECT * FROM users WHERE username='".$this->db->escapeString($this->msg->from)."'");
		$row = $res->fetchArray(SQLITE3_ASSOC);
		if (!$row['accepted']) {
			$res2 = $this->db->query("SELECT id,sender,amount from txes where to='".$row['username']."'");
			while ($row2 = $res2->fetchArray(SQLITE3_ASSOC)) {
				// reverse the transaction
				try {
				$dogetip->move(ACCOUNT_PREFIX.$row['username'],ACCOUNT_PREFIX.$row2['sender'],$row2['amount']);
				} catch (Exception $e) { echo "Moving an unclaimed tip from ".$row['username']." back to ".$row2['sender']." (amount : ".$row2['amount']." ) failed.zn"; } // I know.. I know..
				$this->db->query("DELETE FROM txes where id=".$row2['id']);
			}
		}
		$this->db->query("UPDATE users set blocked=1 where username='".$this->db->escapeString($this->msg->from)."'");
		$this->message("You have now been blocked. Sorry to see you go :( - if you ever feel like coming back, message me with the text: unblock");
	}
	
	function msgNoVerify() {
		$exists = (bool)($this->db->querySingle("SELECT count(*) from users where username='".$this->db->escapeString($this->msg->from)."'"));
		if (!$exists) {
			$this->message("You do not have a tipbot account. To create one, message me with the text: tipcreate");
			return;
		}
		$this->db->query("UPDATE users set noverify=1 where username='".$this->db->escapeString($this->msg->from)."'");
		$this->message("You will now no longer get notified whenever you get tipped. If you change your mind about this, message me with the text: verify");
	}
	
	function msgVerify() {
		$exists = (bool)($this->db->querySingle("SELECT count(*) from users where username='".$this->db->escapeString($this->msg->from)."'"));
		if (!$exists) {
			$this->message("You do not have a tipbot account. To create one, message me with the text: tipcreate");
			return;
		}
		$this->db->query("UPDATE users set noverify=0 where username='".$this->db->escapeString($this->msg->from)."'");
		$this->message("You will now get notified whenever you get tipped.");
	}
	
	function msgUnblock() {
		$exists = (bool)($this->db->querySingle("SELECT count(*) from users where username='".$this->db->escapeString($this->msg->from)."'"));
		if (!$exists) {
			$this->message("You do not have a tipbot account. To create one, message me with the text: tipcreate");
			return;
		}
		$this->db->query("UPDATE users set blocked=0 where username='".$this->db->escapeString($this->msg->from)."'");
		$this->message("You have now been unblocked. I knew you'd see sense! :D");
	}
	
	function msgHelp() {
		$exists = (bool)($this->db->querySingle("SELECT count(*) from users where username='".$this->db->escapeString($this->msg->from)."'"));
		if (!$exists) {
			$this->message("Your available commands: tipcreate help");
			return;
		}
		$res = $this->db->query("SELECT * from users where username='".$this->db->escapeString($this->msg->from)."'");
		$row = $res->fetchArray(SQLITE3_ASSOC);
		$this->message("Your available commands: help info withdraw tip history block ".($row['noverify']?"verify":"noverify"));
	}
	
	function msgExit() {
		$this->message("Bye bye!");
		exit(0);
	}
	
	function msgQuery() {
		array_shift($this->msg->body);
		$res = $this->db->query(implode(" ",$this->msg->body));
		if (!is_object($res)) {
			$this->message("Result: ".$res);
			return;
		}
		while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
			$msg = "";
			foreach ($row as $r) $msg .= $r." [::] ";
			$this->message($msg);
		}
		return;
	}
	
	function msgQueryS() {
		array_shift($this->msg->body);
		$res = $this->db->querySingle(implode(" ",$this->msg->body));
		$this->message("Result: ".$res);
	}
	
	function msgEgg() {
		$this->message("MARY!!! Where have you BEEN?! *hugs*");
	}
}
