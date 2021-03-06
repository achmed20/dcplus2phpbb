#!/usr/bin/php
<?php
error_reporting(0);
include "include/class.db.php";
include "include/class.bbcode.php";

	// var_dump($argv);
	// exit;

if(!$argv[1] || !$argv[2]) {
	die("parameters!\ncall like this: \n./dcconvert.php mysql://admin:admin@192.168.12.111:3306/dc2 mysql://admin:admin@192.168.12.111:3306/phpbb\n");
}

$source = parse_url($argv[1]);
$target = parse_url($argv[2]);


$odb = new DB($source["host"], $source["user"], $source["pass"], substr($source["path"], 1), $source["port"]);
$ndb = new DB($target["host"], $target["user"], $target["pass"], substr($target["path"], 1), $target["port"]);

$odb->query("SET NAMES utf8");
$ndb->query("SET NAMES utf8");

$ndb->query("ALTER TABLE `phpbb_topics` ADD COLUMN `dc_old_id` INT UNSIGNED NULL");
$ndb->query("ALTER TABLE `phpbb_posts` ADD COLUMN `dc_old_id` INT UNSIGNED NULL");

// users 
echo "Import Users\n";
$odb->query('select 
				id as f0,
				 0 as f1,
				 2 as f2,
				 unix_timestamp(reg_date) as f3,
				 username as f4,
				 lower(username) as f5,
				 email as f6,
				 "de" as f7,
				 "UTC" as f8,
				  "D M d, Y g:i a" as f9 
			from dcuser where not username="admin"');

$sql = "insert ignore into phpbb_users (user_id, user_type, group_id, user_regdate, username, username_clean, user_email, user_lang, user_timezone, user_dateformat)";
while($row = $odb->next_array(true)) {
	// var_dump($sql.prepareValues($row));
	$ndb->query($sql.prepareValues($row));
}
$ndb->query("insert into phpbb_user_group select group_id, user_id,0,0 from phpbb_users where user_id>100");
$ndb->query("update phpbb_users set user_email_hash = concat(crc32(lower(user_email)), length(user_email))");

// exit;



// ------------------ forums 
echo "Import Forums\n";
$odb->query('SELECT 
				id as f0,
				0 as f1,
				name as f2,
				description as f3,
				1 as f4,
				id as f5,
				id+2 as f6 
			FROM dcforum');
$sql = "insert ignore into phpbb_forums (forum_id, parent_id, forum_name, forum_desc, forum_type, left_id, right_id)";
while($row = $odb->next_array(true)) {
	$ndb->query($sql.prepareValues($row));
}
//fix fucking LEFT_Right ID
$ndb->query('select forum_id from phpbb_forums where parent_id=0 and forum_id>1 order by forum_id asc ');
$forums = $ndb->getAll();
foreach($forums as $f) {
	echo "fixing {$f->forum_id}\n";
	$ndb->query("SELECT forum_id from phpbb_forums where forum_id>{$f->forum_id} and parent_id=0 order by forum_id asc limit 1");
	if($row = $ndb->next()) {
		$ndb->query("update phpbb_forums set right_id={$row->forum_id} where forum_id={$f->forum_id}");
	}
}
// exit;


// exit;
// ------------- forums
$odb->query('SELECT id as f0, parent_id as f1, name as f2, description as f3, 1 as f4, id as f5, id+1 as f6 FROM dcforum');
$sql = "insert ignore into phpbb_forums (forum_id, parent_id, forum_name, forum_desc, forum_type, left_id, right_id)";
$flist = array();
while($row = $odb->next_array(true)) {
	$flist[] = $row["f0"];
	$ndb->query($sql.prepareValues($row));
}

// $flist = array();
// $flist[] = "302";
foreach($flist as $mesg){
	echo "Import forum $mesg\n";

	// ----- topics
	$odb->query('SELECT 
				0 as f0,
					'.$mesg.' as f1,
					subject as f2,
					author_id as f3,
					unix_timestamp(mesg_date) as f4,
					views as f5,
					0 as f6,
					author_name as f7,
					id + '.($mesg*100000).' as f8
				FROM '.$mesg.'_mesg');

	$sql = "insert ignore into phpbb_topics (topic_id, forum_id, topic_title, topic_poster, topic_time, topic_views, topic_first_post_id, topic_first_poster_name, dc_old_id)";

	while($row = $odb->next_array(true)) {
		$ndb->query($sql.prepareValues($row));
	}

	// ----- Posts
	$odb->query('SELECT 0 as f0,
					0 as f1,
					'.$mesg.' as f2,
					unix_timestamp(mesg_date) as f3,
					author_id as f4,
					subject as f5,
					message as f6,
					1 as f7,
					if(top_id>0, top_id + '.($mesg*100000).', id + '.($mesg*100000).') as f8,
					attachments as f9
				FROM '.$mesg.'_mesg');

	$sql = "insert ignore into phpbb_posts (post_id, topic_id, forum_id, post_time, poster_id, post_subject, post_text, post_visibility, dc_old_id)";

	while($row = $odb->next_array(true)) {
		if($row["f9"]) {	//attachments
			$row["f6"].= "\n -- Anhänge -- \n";
			$tmp = explode(",", $row["f9"]);
			foreach ($tmp as $key => $val) {
				$row["f6"].="[url=./files/".$val."]Anhang #".($key+1)." (".$val.")[/url]\n";
			}
		}
		$ndb->query($sql.prepareValues($row, 6, 9));
	}


}

echo "fixing IDs and removing leftover fields";
$ndb->query("ALTER TABLE `phpbb_topics` ADD INDEX `dcold` (`dc_old_id` ASC)");
$ndb->query("ALTER TABLE `phpbb_posts` ADD INDEX `dcold` (`dc_old_id` ASC)");

$ndb->query("update phpbb_topics set topic_first_post_id=(SELECT post_id FROM phpbb_posts where dc_old_id=phpbb_topics.dc_old_id order by post_time asc limit 1)");
$ndb->query("update phpbb_posts set topic_id=(SELECT topic_id FROM phpbb_topics where dc_old_id=phpbb_posts.dc_old_id)");

$ndb->query("ALTER TABLE `phpbb_topics` DROP COLUMN `dc_old_id`, DROP INDEX `dcold`");
$ndb->query("ALTER TABLE `phpbb_posts` DROP COLUMN `dc_old_id`, DROP INDEX `dcold`");



#####################################################################

function prepareValues($row, $bbfield=-1, $attachments=-1) {
	$vals = array();
	for ($i=0; $i < sizeof($row); $i++) { 
		if($i==$bbfield) {
			$row["f".$i] = bbcode::tohtml($row["f".$i],TRUE);
		}
		if($i==$attachments) {
			// $vals[$i] = "'".db::fix($row["f".$i])."'";
		} else {
			$vals[$i] = "'".db::fix($row["f".$i])."'";
		}
	}
	return " VALUES (".implode(",", $vals).")";

}