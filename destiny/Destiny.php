<?php

namespace Destiny;

use Destiny\Character\ActivityCollection;
use Destiny\Character\Inventory;
use Destiny\Character\ProgressionCollection;
use Destiny\Character\RecordBookCollection;

class Destiny
{
    protected $client;
    protected $platform;

    public function __construct(DestinyClient $client, DestinyPlatform $platform)
    {
        $this->client = $client;
        $this->platform = $platform;
    }

    /**
     * @return array
     */
    public function manifest()
    {
        return $this->client->request($this->platform->manifest());
    }

    /**
     * @return Advisors
     */
    public function advisors()
    {
        $result = $this->client->request($this->platform->advisors());

        return new Advisors($result['data']);
    }

    /**
     * @return Xur
     */
    public function xur()
    {
        $result = $this->client->request($this->platform->xur());

        return new Xur($result['data']);
    }

    /**
     * @param string $gamertag
     *
     * @return \Destiny\PlayerCollection
     */
    public function player($gamertag)
    {
        $result = $this->client->request($this->platform->searchDestinyPlayer($gamertag));

        return new PlayerCollection($gamertag, $result);
    }

    /**
     * @param \Destiny\Player $player
     *
     * @return \Destiny\Account
     */
    public function account(Player $player)
    {
        $results = $this->client->request([
            'account' => $this->platform->account($player),
            'stats'   => $this->platform->statsAccount($player),
        ]);

        return new Account($player, $results['account']['data'], $results['stats']);
    }

    /**
     * @param Account $account
     *
     * @return array
     */
    public function accountAggregated(Account $account)
    {
        $requests = [];

        foreach ($account->characters as $character) {
            $cid = $character->characterId;
            $requests["$cid.activitystats"] = $this->platform->statsActivityAggregated($character);
        }

        $results = $this->client->request($requests);

        $mergedStats = [];
        foreach ($account->characters as $character) {
            $cid = $character->characterId;
            foreach (array_get($results["$cid.activitystats"], 'data.activities', []) as $activity) {
                $completion = $activity['values']['activityCompletions']['basic']['value'];
                $activityHash = $this->checkForFeatured($activity['activityHash']);
                $activity = manifest()->activity($activityHash);

                if ($completion > 0 && $activity->activityType->isRaid()) {
                    if (!isset($mergedStats[$activityHash])) {
                        $mergedStats[$activityHash] = $activity;
                        $mergedStats[$activityHash]['completions'] = $completion;
                        $mergedStats[$activityHash]['name'] = $this->raidNames($activityHash);
                    } else {
                        $mergedStats[$activityHash]['completions'] += $completion;
                    }
                }
            }
        }

        return array_reverse(array_sort($mergedStats, function ($item) {
            return $item['completions'];
        }));
    }

    /**
     * @param \Destiny\Account $account
     *
     * @return \Destiny\Account
     */
    public function accountDetails(Account $account)
    {
        $requests = [];

        foreach ($account->characters as $character) {
            $cid = $character->characterId;

            $requests["$cid.activitystats"] = $this->platform->statsActivityAggregated($character);
            $requests["$cid.inventory"] = $this->platform->inventory($character);
            $requests["$cid.progression"] = $this->platform->progression($character);
            $requests["$cid.checklist"] = $this->platform->checklist($character);
        }

        $results = $this->client->request($requests);

        foreach ($account->characters as $character) {
            $cid = $character->characterId;

            $activityStats = array_get($results["$cid.activitystats"], 'data.activities', []);
            $inventory = array_get($results["$cid.inventory"], 'data', []);
            $progression = array_get($results["$cid.progression"], 'data', []);

            if (!isset($results["$cid.checklist"]['private'])) {
                $checklist = array_get($results["$cid.checklist"], 'data', []);
            } else {
                $checklist = [];
            }

            $character->activities = new ActivityCollection($character, $activityStats, $checklist);
            $character->inventory = new Inventory($character, $inventory);
            $character->progression = new ProgressionCollection($character, $progression);
        }

        return $account;
    }

    public function recordBooks(Account $account)
    {
        $character = $account->characters->first();
        $request = $this->platform->checklist($character);
        $result = $this->client->request($request);

        if (!isset($result['private'])) {
            $checklist = array_get($result, 'data', []);
        } else {
            $checklist = [];
        }

        return new RecordBookCollection($character, $checklist);
    }

    /**
     * @param \Destiny\Player $player
     *
     * @return \Destiny\Grimoire
     */
    public function grimoire(Player $player)
    {
        $results = $this->client->request([
            'account'  => $this->platform->account($player),
            'grimoire' => $this->platform->grimoire($player),
        ]);

        $player->account = new Account($player, $results['account']['data']);

        return new Grimoire($player, $results['grimoire']['data']);
    }

    public function news()
    {
        return $this->client->request($this->platform->news('content/site/homepage/en/', next_daily()))['blog.Response'];
    }

    /**
     * @param $activityHash
     *
     * @return string
     */
    private function raidNames($activityHash)
    {
        switch ($activityHash) {
            case '2659248068':
            case '260765522':
            case '1733556769':
            case '2659248071':
                return 'Normal Mode';
            case '1836893119':
            case '1387993552':
            case '3534581229':
            case '1836893116':
                return 'Hard Mode';
            case '4000873610':
            case '856898338':
            case '3978884648':
            case '3356249023':
                return '390LL Mode';
            default:
                return 'UNK - '.$activityHash;

        }
    }

    /**
     * @param $activityHash
     *
     * @return string
     */
    private function checkForFeatured($activityHash)
    {
        switch ($activityHash) {
            case '4038697181': // WOTM
                return '856898338';
            case '2324706853': // Crota
                return '4000873610';
            case '1016659723': // KF
                return '3978884648';
            case '430160982': // VOG
                return '3356249023';
            default:
                return $activityHash;
        }
    }
}
