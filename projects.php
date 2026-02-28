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
    $has_next_page = true;
    $cursor = null;

    while ($has_next_page) {
        $query = 'query($cursor: String) {
          user(login: "KovuTheHusky") {
            repositoriesContributedTo(first: 100, after: $cursor, contributionTypes: [COMMIT, ISSUE, PULL_REQUEST, REPOSITORY], includeUserRepositories: true) {
              nodes {
                nameWithOwner
              }
              pageInfo {
                hasNextPage
                endCursor
              }
            }
          }
        }';

        $payload = json_encode([
            "query" => $query,
            "variables" => ["cursor" => $cursor],
        ]);

        $ch_gql = curl_init("https://api.github.com/graphql");
        curl_setopt($ch_gql, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: bearer " . GITHUB_SECRET,
            "User-Agent: PHP",
        ]);
        curl_setopt($ch_gql, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_gql, CURLOPT_POST, true);
        curl_setopt($ch_gql, CURLOPT_POSTFIELDS, $payload);

        $response_gql = curl_exec($ch_gql);
        $http_code_gql = curl_getinfo($ch_gql, CURLINFO_HTTP_CODE);
        curl_close($ch_gql);

        if ($response_gql && $http_code_gql == 200) {
            $data = json_decode($response_gql, true);

            if (isset($data["data"]["user"]["repositoriesContributedTo"])) {
                $repoData = $data["data"]["user"]["repositoriesContributedTo"];

                foreach ($repoData["nodes"] as $node) {
                    $repoName = $node["nameWithOwner"];
                    $parts = explode("/", $repoName);

                    if (
                        strtolower($repoName) === "kovuthehusky/kovuthehusky" ||
                        (count($parts) == 2 &&
                            strtolower($parts[1]) === ".github")
                    ) {
                        continue;
                    }

                    if (!in_array($repoName, $json->contributions)) {
                        $json->contributions[] = $repoName;
                    }
                }

                $has_next_page = $repoData["pageInfo"]["hasNextPage"];
                $cursor = $repoData["pageInfo"]["endCursor"];
            } else {
                $has_next_page = false;
                error_log(
                    "GraphQL query failed to return expected structure: " .
                        $response_gql,
                );
                $cli_success = false;
            }
        } else {
            $has_next_page = false;
            error_log("GraphQL request failed with HTTP $http_code_gql");
            $cli_success = false;
        }
    }

    $page = 1;
    while (true) {
        $ch_repos = curl_init(
            "https://api.github.com/user/repos?visibility=public&affiliation=owner,collaborator,organization_member&per_page=100&page={$page}",
        );
        curl_setopt($ch_repos, CURLOPT_HTTPHEADER, [
            "Accept: application/vnd.github.v3+json",
            "Authorization: token " . GITHUB_SECRET,
            "User-Agent: PHP",
        ]);
        curl_setopt($ch_repos, CURLOPT_RETURNTRANSFER, true);

        $repos_response = curl_exec($ch_repos);
        $http_code_repos = curl_getinfo($ch_repos, CURLINFO_HTTP_CODE);
        curl_close($ch_repos);

        if ($repos_response && $http_code_repos == 200) {
            $repos_data = json_decode($repos_response);

            if (empty($repos_data)) {
                break;
            }

            foreach ($repos_data as $repo) {
                $repoName = $repo->full_name;
                $parts = explode("/", $repoName);

                if (
                    strtolower($repoName) === "kovuthehusky/kovuthehusky" ||
                    (count($parts) == 2 && strtolower($parts[1]) === ".github")
                ) {
                    continue;
                }

                if (!in_array($repoName, $json->contributions)) {
                    $json->contributions[] = $repoName;
                }
            }
            $page++;
        } else {
            error_log(
                "Failed to fetch user/org repos. HTTP Code: $http_code_repos",
            );
            $cli_success = false;
            break;
        }
    }

    if ($cli_success) {
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
    "User-Agent: PHP",
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$orgs = [];
if ($response) {
    $response_decoded = json_decode($response);
    if (is_array($response_decoded)) {
        foreach ($response_decoded as $org) {
            $orgs[] = $org->login;
        }
    }
}
curl_close($ch);

$json->owned = new stdClass();
$json->forked = new stdClass();

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
    $json->forked->{$status} = [];
}

$json->contributed = [];

foreach ($json->contributions as $key => $repo) {
    $parts = explode("/", $repo);
    if (
        strtolower($repo) === "kovuthehusky/kovuthehusky" ||
        (count($parts) == 2 && strtolower($parts[1]) === ".github")
    ) {
        unset($json->contributions[$key]);
        continue;
    }

    $ch = curl_init("https://api.github.com/repos/" . $repo);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Authorization: token " . GITHUB_SECRET,
        "User-Agent: PHP",
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response && $http_code == 200) {
        $response_obj = json_decode($response);

        $owner = $response_obj->owner->login;
        $name = $response_obj->name;
        $default_branch = isset($response_obj->default_branch)
            ? $response_obj->default_branch
            : "master";

        $project = new stdClass();
        $project->name = $name;
        $project->owner = $owner;
        $project->description = $response_obj->description;
        $project->forks = $response_obj->forks;
        $project->stars = $response_obj->stargazers_count;

        if ($owner == "KovuTheHusky" || in_array($owner, $orgs)) {
            $homepage = $response_obj->homepage;
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
                    "User-Agent: PHP",
                ]);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                $response2 = curl_exec($ch2);
                $http_code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                curl_close($ch2);

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

            if ($line !== "") {
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

            if (isset($response_obj->fork) && $response_obj->fork === true) {
                if (!isset($json->forked->{$status_lower})) {
                    $json->forked->{$status_lower} = [];
                }
                $json->forked->{$status_lower}[] = $project;
            } else {
                if (!isset($json->owned->{$status_lower})) {
                    $json->owned->{$status_lower} = [];
                }
                $json->owned->{$status_lower}[] = $project;
            }
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
