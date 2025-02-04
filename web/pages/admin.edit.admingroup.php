<?php
/*************************************************************************
This file is part of SourceBans++

SourceBans++ (c) 2014-2019 by SourceBans++ Dev Team

The SourceBans++ Web panel is licensed under a
Creative Commons Attribution-NonCommercial-ShareAlike 3.0 Unported License.

You should have received a copy of the license along with this
work.  If not, see <http://creativecommons.org/licenses/by-nc-sa/3.0/>.

This program is based off work covered by the following copyright(s):
SourceBans 1.4.11
Copyright © 2007-2014 SourceBans Team - Part of GameConnect
Licensed under CC-BY-NC-SA 3.0
Page: <http://www.sourcebans.net/> - <http://www.gameconnect.net/>
*************************************************************************/

if (!defined("IN_SB")) {
    echo "You should not be here. Only follow links!";
    die();
}
global $userbank, $theme;

new AdminTabs([], $userbank, $theme);

if (!isset($_GET['id'])) {
    echo '<div id="msg-red" >
	<i class="fas fa-times fa-2x"></i>
	<b>Error</b>
	<br />
	No admin id specified. Please only follow links
</div>';
    PageDie();
}

$_GET['id'] = (int) $_GET['id'];
if (!$userbank->HasAccess(ADMIN_OWNER | ADMIN_EDIT_ADMINS)) {
    Log::add("w", "Hacking Attempt", $userbank->GetProperty("user")." tried to edit ".$userbank->GetProperty('user', $_GET['id'])."'s groups, but doesn't have access.");
    echo '<div id="msg-red" >
	<i class="fas fa-times fa-2x"></i>
	<b>Error</b>
	<br />
	You are not allowed to edit other admin\'s groups.
</div>';
    PageDie();
}

if (!$userbank->GetProperty("user", $_GET['id'])) {
    Log::add("e", "Getting admin data failed", "Can't find data for admin with id $_GET[id].");
    echo '<div id="msg-red" >
	<i class="fas fa-times fa-2x"></i>
	<b>Error</b>
	<br />
	Error getting current data.</div>';
    PageDie();
}

// Form sent
if (isset($_POST['wg']) || isset($_GET['wg'])) {
    if (isset($_GET['wg'])) {
        $_POST['wg'] = $_GET['wg'];
    }

    $_POST['wg'] = (int) $_POST['wg'];

    // Users require a password and email to have web permissions
    $password = $GLOBALS['userbank']->GetProperty('password', $_GET['id']);
    $email    = $GLOBALS['userbank']->GetProperty('email', $_GET['id']);
    if ($_POST['wg'] > 0 && (empty($password) || empty($email))) {
        echo '<script>ShowBox("Error", "Admins have to have a password and email set in order to get web permissions.<br /><a href=\"index.php?p=admin&c=admins&o=editdetails&id=' . $_GET['id'] . '\" title=\"Edit Admin Details\">Set the details</a> first and try again.", "red");</script>';
    } else {
        if (isset($_POST['wg']) && $_POST['wg'] != "-2") {
            if ($_POST['wg'] == -1) {
                $_POST['wg'] = 0;
            }
            // Edit the web group
            $edit = $GLOBALS['db']->Execute(
                "UPDATE " . DB_PREFIX . "_admins SET
                `gid` = ?
                WHERE `aid` = ?;",
                array(
                    $_POST['wg'],
                    $_GET['id']
                )
            );
        }
		
		$exists = true;
		$counter = 1;
		
		while($exists) {
			$srvgroup = "sg_". $counter;
			if (!isset($_POST[$srvgroup])) {
				if($counter == 1) {
					$counter = 0;
				}
				$exists = false;
			} else {
				$counter++;
			}
		}
		
		if($counter != 0) {
			//Do this for each server.
			$highestImmunityGroup = NULL;
			for($serverID = 0; $serverID <= $counter; $serverID++) {
				$srvgroup = "sg_". $serverID;
				if (isset($_POST[$srvgroup]) && $_POST[$srvgroup] != "-2") {
					// Edit the server admin group
					
					$group = "";
					if ($_POST[$srvgroup] != -1) {
						$grps = $GLOBALS['db']->GetRow("SELECT name, immunity FROM " . DB_PREFIX . "_srvgroups WHERE id = ?;", array(
							$_POST[$srvgroup]
						));
						if ($grps) {
							$group = $grps['name'];
							$gImmunity = (int) $grps['immunity'];
						}
					}
					
					if($highestImmunityGroup == NULL) {
						$edit = $GLOBALS['db']->Execute(
							"UPDATE " . DB_PREFIX . "_admins SET
							`srv_group` = ?
							WHERE aid = ?",
							array(
								$group,
								$_GET['id']
							)
						);
						$highestImmunityGroup = $group. ",". $gImmunity;
					} else {
						$highestImmunityGroup = explode(",", $highestImmunityGroup);
						
						if($gImmunity > $highestImmunityGroup[1]) {
							$edit = $GLOBALS['db']->Execute(
								"UPDATE " . DB_PREFIX . "_admins SET
								`srv_group` = ?
								WHERE aid = ?",
								array(
									$group,
									$_GET['id']
								)
							);
							$highestImmunityGroup = $group. ",". $gImmunity;
						}
					}

					$edit = $GLOBALS['db']->Execute(
						"UPDATE " . DB_PREFIX . "_admins_servers_groups SET
						`group_id` = ?
						WHERE admin_id = ? AND server_id = ?;",
						array(
							$_POST[$srvgroup],
							$_GET['id'],
							$serverID
						)
					);
				}
			}
		}
		
        if (Config::getBool('config.enableadminrehashing')) {
            // rehash the admins on the servers
            $serveraccessq = $GLOBALS['db']->GetAll("SELECT s.sid FROM `" . DB_PREFIX . "_servers` s
                LEFT JOIN `" . DB_PREFIX . "_admins_servers_groups` asg ON asg.admin_id = '" . (int) $_GET['id'] . "'
                LEFT JOIN `" . DB_PREFIX . "_servers_groups` sg ON sg.group_id = asg.srv_group_id
                WHERE ((asg.server_id != '-1' AND asg.srv_group_id = '-1')
                OR (asg.srv_group_id != '-1' AND asg.server_id = '-1'))
                AND (s.sid IN(asg.server_id) OR s.sid IN(sg.server_id)) AND s.enabled = 1");
            $allservers    = [];
            foreach ($serveraccessq as $access) {
                if (!in_array($access['sid'], $allservers)) {
                    $allservers[] = $access['sid'];
                }
            }
            echo '<script>ShowRehashBox("' . implode(",", $allservers) . '", "Admin updated", "The admin has been updated successfully", "green", "index.php?p=admin&c=admins");TabToReload();</script>';
        } else {
            echo '<script>ShowBox("Admin updated", "The admin has been updated successfully", "green", "index.php?p=admin&c=admins");TabToReload();</script>';
        }

        $admname = $GLOBALS['db']->GetRow("SELECT user FROM `" . DB_PREFIX . "_admins` WHERE aid = ?", array(
            (int) $_GET['id']
        ));
        Log::add("m", "Admin's Groups Updated", "Admin ($admname[user]) groups has been updated.");
    }
}

$server_list = $GLOBALS['db']->GetAll("SELECT * FROM `" . DB_PREFIX . "_servers`");
$wgroups = $GLOBALS['db']->GetAll("SELECT gid, name FROM " . DB_PREFIX . "_groups WHERE type != 3");
$sgroups = $GLOBALS['db']->GetAll("SELECT id, name FROM " . DB_PREFIX . "_srvgroups");
$asgroups = $GLOBALS['db']->GetAll("SELECT group_id, server_id FROM " . DB_PREFIX . "_admins_servers_groups WHERE admin_id = " . $_GET['id']);

$server_admin_group = "";
foreach ($asgroups as $asg) {
	foreach ($sgroups as $sg) {
		if ($sg['id'] == $asg['group_id']) {
			$server_admin_group_id = $asg['server_id']. "_admin_group_id";
			$theme->assign($server_admin_group_id, $sg['id']);
			break;
		}
	}
}

$theme->assign('group_admin_name', $userbank->GetProperty("user", $_GET['id']));
$theme->assign('group_admin_id', $userbank->GetProperty("gid", $_GET['id']));
$theme->assign('group_lst', $sgroups);
$theme->assign('server_list', $server_list);
$theme->assign('web_lst', $wgroups);

$theme->display('page_admin_edit_admins_group.tpl');
