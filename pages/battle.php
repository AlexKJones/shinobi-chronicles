<?php
/*
File: 		battle.php
Coder:		Levi Meahan
Created:	12/18/2013
Revised:	05/02/2014 by Levi Meahan
Purpose:	Functions for initiating combat and distributing post-combat rewards
Algorithm:	See master_plan.html
*/

/**
 * @return bool
 * @throws Exception
 */
function battle(): bool {
	global $system;
	global $player;
	global $self_link;

	if($player->battle_id) {
        $battle = new BattleManager($system, $player, $player->battle_id);

        $battle->checkInputAndRunTurn();

        $battle->renderBattle();

        if($battle->isComplete()) {
            $player->battle_id = 0;
            $result = processBattleFightEnd($battle, $player);

			echo "<table class='table'>
                <tr><th>Battle complete</th></tr>
			    <tr><td style='text-align:center;'>" . str_replace("[br]", "<br />", $result) . "</td></tr>
            </table>";
		}
	}
	else if($_GET['attack']) {
		try {
			$attack_id = (int)$system->clean($_GET['attack']);

			try {
			    $user = new User($attack_id);
			    $user->loadData(User::UPDATE_NOTHING, true);
            } catch(Exception $e) {
                throw new Exception("Invalid user! " . $e->getMessage());
            }

			if($user->village == $player->village) {
				throw new Exception("You cannot attack people from your own village!");
			}
			if($user->rank < 3) {
				throw new Exception("You cannot attack people below Chuunin rank!");
			}
			if($player->rank < 3) {
				throw new Exception("You cannot attack people Chuunin rank and higher!");
			}
			if($user->location !== $player->location) {
				throw new Exception("Target is not at your location!");
			}
			if($user->battle_id) {
				throw new Exception("Target is in battle!");
			}
			if($user->last_active < time() - 120) {
				throw new Exception("Target is inactive/offline!");
			}
			if($player->last_death > time() - 60) {
				throw new Exception("You died within the last minute, please wait " .
					(($player->last_death + 60) - time()) . " more seconds.");
			}
			if($user->last_death > time() - 60) {
				throw new Exception("Target has died within the last minute, please wait " .
					(($user->last_death + 60) - time()) . " more seconds.");
			}

			Battle::start($system, $player, $user, Battle::TYPE_FIGHT);
			$system->message("You have attacked!<br />
				<a class='link' href='$self_link'>To Battle</a>");
			$system->printMessage();
		} catch (Exception $e) {
			$system->message($e->getMessage());
			$system->printMessage();

			NearbyPlayers::renderScoutAreaList($system, $player, $self_link);
		}
	}
	else {
        NearbyPlayers::renderScoutAreaList($system, $player, $self_link);
	}
	return true;
}

/**
 * @throws Exception
 */
function processBattleFightEnd(BattleManager $battle, User $player): string {
    $pvp_yen = $player->rank * 50;

    $result = "";
    
    if($battle->isPlayerWinner()) {
        $player->pvp_wins++;
        $player->monthly_pvp++;
        $player->last_pvp = time();
        $village_point_gain = 1;
        $team_point_gain = 1;

        $player->money += $pvp_yen;
        $result .= "You win the fight and earn ¥$pvp_yen![br]";

        $player->system->query("UPDATE `villages` SET `points`=`points`+'$village_point_gain' WHERE `name`='$player->village' LIMIT 1");
        $result .= "You have earned $village_point_gain point for your village.[br]";
        
        // Team points
        if($player->team != null) {
            $player->team->addPoints($team_point_gain);

            $result .= "You have earned $team_point_gain point for your team.[br]";
        }
        // Daily Tasks
        foreach ($player->daily_tasks as $task) {
            if ($task->activity == DailyTask::ACTIVITY_PVP && !$task->complete) {
                $task->progress++;
            }
        }
    }
    else if($battle->isOpponentWinner()) {
        $result .= "You lose. You were taken back to your village by some allied ninja.[br]";
        $player->health = 5;
        $player->pvp_losses++;
        $player->last_pvp = time();
        $player->last_death = time();
        $player->moveToVillage();
        // Daily Tasks
        foreach ($player->daily_tasks as $task) {
            if ($task->activity == DailyTask::ACTIVITY_PVP && $task->sub_task == DailyTask::SUB_TASK_COMPLETE && !$task->complete) {
                $task->progress++;
            }
        }
    }
    else {
        $result .= "You both knocked each other out. You were taken back to your village by some allied ninja.[br]";
        $player->health = 5;
        $player->moveToVillage();
        $player->last_pvp = time();

        // Daily Tasks
        foreach ($player->daily_tasks as $task) {
            if ($task->activity == DailyTask::ACTIVITY_PVP && $task->sub_task == DailyTask::SUB_TASK_COMPLETE && !$task->complete) {
                $task->progress++;
            }
        }
    }

    return $result;
}

function battleFightAPI(System $system, User $player): BattlePageAPIResponse {
    if(!$player->battle_id) {
        return new BattlePageAPIResponse(errors: ["Player is not in battle!"]);
    }

    $response = new BattlePageAPIResponse();

    try {
        $battle = new BattleManager($system, $player, $player->battle_id);
        $battle->checkInputAndRunTurn();

        $response->battle_data = $battle->getApiResponse();

        if($battle->isComplete()) {
            $response->battle_result = processBattleFightEnd($battle, $player);
        }
    }
    catch (Exception $e) {
        $response->errors[] = $e->getMessage();
    }

    return $response;
}
