<?php

if (php_sapi_name() != "cli") {
    exit();
}

declare(ticks=1);

function shutdown()
{
    if (file_exists("minecraft.txt")) {
        unlink("minecraft.txt");
    }
    file_put_contents("minecraft.json", "", LOCK_EX);
    exit();
}

register_shutdown_function("shutdown");
pcntl_signal(SIGINT, "shutdown");
pcntl_signal(SIGTERM, "shutdown");

date_default_timezone_set("UTC");
error_reporting(E_ALL);
ini_set("log_errors", 1);
ini_set("error_log", "minecraft.log");

use xPaw\MinecraftQuery;
use xPaw\MinecraftQueryException;
use xPaw\SourceQuery\SourceQuery;

require __DIR__ . "/configuration.php";
require __DIR__ . "/vendor/autoload.php";

if (file_exists("minecraft.json") && filesize("minecraft.json") > 0) {
    $json = json_decode(file_get_contents("minecraft.json"));
} else {
    $json = new stdClass();
    $json->Info = new stdClass();
    $json->Players = [];
    $json->Tickrate = array_fill(0, 60, 20.0);
    $json->Ticktime = array_fill(0, 60, 0.0);
}

while (true) {
    $query = new MinecraftQuery();
    $rcon = new SourceQuery();

    try {
        $rcon->Connect(MINECRAFT_IP, 25575, 1);
        $rcon->SetRconPassword(MINECRAFT_PASSWORD);

        while (true) {
            time_sleep_until(time() + 5);

            while (count($json->Tickrate) >= 60) {
                array_shift($json->Tickrate);
            }
            while (count($json->Ticktime) >= 60) {
                array_shift($json->Ticktime);
            }

            $query->Connect(MINECRAFT_IP, 25565, 1);
            $json->Info = $query->GetInfo();
            $players = $query->GetPlayers();
            $json->Players = $players ? $players : [];

            $timeOutput = $rcon->Rcon("time query time");
            $cleanTimeStr = preg_replace('/\xA7[0-9A-FK-OR]/i', '', $timeOutput);
            $totalTicks = (int) preg_replace('/[^0-9]/', '', $cleanTimeStr);
            $cleanDay = (int) floor($totalTicks / 24000);
            $cleanTime = $totalTicks % 24000;
            $json->Day = $cleanDay + 1;
            $json->Time = $cleanTime;

            $rainingRaw = $rcon->Rcon(
                'execute if predicate [{"condition":"minecraft:weather_check","raining":true}]',
            );
            $raining = strpos($rainingRaw, "passed") !== false;

            $thunderRaw = $rcon->Rcon(
                'execute if predicate [{"condition":"minecraft:weather_check","thundering":true}]',
            );
            $thundering = strpos($thunderRaw, "passed") !== false;

            $json->Weather = $thundering
                ? "thunder"
                : ($raining
                    ? "rain"
                    : "clear");

            $moonPhases = [
                "full_moon",
                "waning_gibbous",
                "last_quarter",
                "waning_crescent",
                "new_moon",
                "waxing_crescent",
                "first_quarter",
                "waxing_gibbous",
            ];
            $json->Moon = $moonPhases[$cleanDay % 8];

            $msptOutput = $rcon->Rcon("mspt");
            $msptLines = explode(PHP_EOL, $msptOutput);
            $msptTargetLine = isset($msptLines[1])
                ? $msptLines[1]
                : $msptLines[0];
            $msptClean = preg_replace(
                '/\xA7[0-9A-FK-OR]/i',
                "",
                $msptTargetLine,
            );
            $msptClean = str_replace("◴ ", "", $msptClean);
            $msptGroups = explode(", ", $msptClean);
            $msptValues = explode("/", $msptGroups[0]);
            $json->Ticktime[] = (float) preg_replace(
                "/[^0-9.]/",
                "",
                $msptValues[0],
            );

            $tpsRaw = $rcon->Rcon("tps");
            $tpsClean = preg_replace('/\xA7[0-9A-FK-OR]/i', "", $tpsRaw);
            $tpsMatch = explode(": ", $tpsClean);
            if (isset($tpsMatch[1])) {
                $tpsValues = explode(", ", $tpsMatch[1]);
                $tps5s = preg_replace("/[^0-9.]/", "", $tpsValues[0]);
                $json->Tickrate[] = (float) $tps5s;
            } else {
                $json->Tickrate[] = 20.0;
            }

            $json->Updated = time();

            file_put_contents("minecraft.txt", 1, LOCK_EX);
            file_put_contents("minecraft.json", json_encode($json), LOCK_EX);
        }
    } catch (Throwable $e) {
        if (file_exists("minecraft.txt")) {
            unlink("minecraft.txt");
        }
        file_put_contents("minecraft.json", "", LOCK_EX);

        error_log($e->getMessage());

        sleep(5);
    }
}
