<?php

if (php_sapi_name() != "cli") {
    exit();
}

date_default_timezone_set("UTC");
error_reporting(E_ALL);
ini_set("log_errors", 1);
ini_set("error_log", "projects.log");

require __DIR__ . "/configuration.php";

$json = json_decode(file_get_contents("projects.json"));

$since = $json->since;
$today = date("Y-m-d");
$cli_success = true;

if ($since != $today) {
    $out = `node_modules/@ghuser/github-contribs/cli.js --since {$since} KovuTheHusky 2>&1`;

    if (empty(trim($out)) || stripos($out, "error") !== false) {
        error_log(
            "GitHub Contribs CLI failed or returned empty/error: " . $out,
        );
        $cli_success = false;
    } else {
        $separator = "\r\n";
        $line = strtok($out, $separator);

        while ($line !== false) {
            if (
                strpos($line, "/") !== false &&
                !in_array($line, $json->contributions)
            ) {
                $json->contributions[] = $line;
            }
            $line = strtok($separator);
        }

        usort($json->contributions, function ($a, $b) {
            return strcmp(
                explode("/", strtolower($a))[1],
                explode("/", strtolower($b))[1],
            );
        });
    }
}

$ch = curl_init("https://api.github.com/user/orgs");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Accept: application/json",
    "Authorization: token " . GITHUB_SECRET,
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERAGENT, "PHP");
$response = curl_exec($ch);
$orgs = [];
if ($response) {
    $response = json_decode($response);
    if (is_array($response)) {
        foreach ($response as $org) {
            $orgs[] = $org->login;
        }
    }
}

$json->owned = new stdClass();

$statuses = [
    "active",
    "inactive",
    "unsupported",
    "suspended",
    "abandoned",
    "wip",
    "concept",
    "moved",
    "unknown",
];
foreach ($statuses as $status) {
    $json->owned->{$status} = [];
}

$json->contributed = [];

foreach ($json->contributions as $repo) {
    $ch = curl_init("https://api.github.com/repos/" . $repo);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Authorization: token " . GITHUB_SECRET,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "PHP");

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response && $http_code == 200) {
        $response = json_decode($response);

        $owner = $response->owner->login;
        $name = $response->name;

        $default_branch = isset($response->default_branch)
            ? $response->default_branch
            : "master";

        $project = new stdClass();
        $project->name = $name;
        $project->owner = $owner;
        $project->description = $response->description;
        $project->forks = $response->forks;
        $project->stars = $response->stargazers_count;

        if ($owner == "KovuTheHusky" || in_array($owner, $orgs)) {
            $homepage = $response->homepage;
            $homepage_text = "Visit";
            if (
                !$homepage ||
                strpos($homepage, "https://kovu.dog/projects") === 0
            ) {
                $homepage = null;
                $homepage_text = null;
            }
            if (!$homepage) {
                $ch2 = curl_init(
                    "https://api.github.com/repos/" .
                        $repo .
                        "/releases/latest",
                );
                curl_setopt($ch2, CURLOPT_HTTPHEADER, [
                    "Accept: application/json",
                    "Authorization: token " . GITHUB_SECRET,
                ]);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch2, CURLOPT_USERAGENT, "PHP");
                $response2 = curl_exec($ch2);
                $http_code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);

                if ($response2 && $http_code2 == 200) {
                    $homepage =
                        "https://github.com/" . $repo . "/releases/latest";
                    $homepage_text = "Download";
                }
            }
            if ($homepage) {
                $project->button = [
                    "text" => $homepage_text,
                    "href" => $homepage,
                ];
            }

            $project->source = "https://github.com/" . $repo;

            $slug = preg_replace("/[^a-z0-9]/", "", strtolower($name));

            if (
                @get_headers(
                    "https://raw.githubusercontent.com/KovuTheHusky/kovu.dog/master/images/projects/icons/{$slug}.svg",
                )[0] == "HTTP/1.1 200 OK"
            ) {
                $project->icon = "/images/projects/icons/{$slug}.svg";
            } elseif (
                @get_headers(
                    "https://raw.githubusercontent.com/KovuTheHusky/kovu.dog/master/images/projects/icons/{$slug}.webp",
                )[0] == "HTTP/1.1 200 OK"
            ) {
                $project->icon = "/images/projects/icons/{$slug}.webp";
            }

            if (
                @get_headers(
                    "https://raw.githubusercontent.com/KovuTheHusky/kovu.dog/master/videos/projects/previews/{$slug}.mp4",
                )[0] == "HTTP/1.1 200 OK"
            ) {
                $project->preview_video = "/videos/projects/previews/{$slug}.mp4";
            } elseif (
                @get_headers(
                    "https://raw.githubusercontent.com/KovuTheHusky/kovu.dog/master/images/projects/previews/{$slug}.webp",
                )[0] == "HTTP/1.1 200 OK"
            ) {
                $project->preview_image = "/images/projects/previews/{$slug}.webp";
            }

            $readme_url = "https://raw.githubusercontent.com/{$repo}/{$default_branch}/README.md";
            $readme_content = @file_get_contents($readme_url);
            $line = "";
            if ($readme_content !== false) {
                $line = explode("\n", $readme_content)[0];
            }

            if ($repo != "KovuTheHusky/KovuTheHusky" && $line !== "") {
                $match = preg_match('/^#?\s*([^\[]+).*$/', $line, $matches);
                if ($match) {
                    $project->name = trim($matches[1]);
                }
            }

            $match = preg_match(
                "/http[s]?:\/\/.*repostatus\.org\/badges\/.+?\/(.+?)\.svg/",
                $line,
                $matches,
            );
            if ($match) {
                $status = $matches[1];
                if ($status == "wip") {
                    $status = "WIP";
                } else {
                    $status = ucfirst($status);
                }
            } else {
                $status = "Unknown";
            }
            $project->status = $status;
            $status_lower = strtolower($status);

            if (!isset($json->owned->{$status_lower})) {
                $json->owned->{$status_lower} = [];
            }
            $json->owned->{$status_lower}[] = $project;
        } else {
            $json->contributed[] = $project;
        }
    } else {
        if ($response) {
            $response_obj = json_decode($response);
            if (
                isset($response_obj->message) &&
                ($response_obj->message == "Moved Permanently" ||
                    $response_obj->message == "Not Found")
            ) {
                $key = array_search($repo, $json->contributions);
                if ($key !== false) {
                    unset($json->contributions[$key]);
                }
            } elseif ($http_code == 403 || $http_code == 429) {
                error_log("GitHub API rate limit hit for repo: $repo");
            }
        } else {
            error_log(
                "cURL completely failed for repo: $repo. Network timeout?",
            );
        }
    }
}

if ($cli_success) {
    $json->since = $today;
}
$json->contributions = array_values($json->contributions);

file_put_contents(
    "projects.json",
    json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
);
