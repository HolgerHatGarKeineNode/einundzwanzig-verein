<?php

namespace App\Services\Election;

use App\Exceptions\ElectionCandidateBlockedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Models\MemberProposal;
use UnexpectedValueException;

/**
 * Why the board cannot take a step on a round, in the German the board's
 * page speaks. The run sheet uses it to explain a round it does not offer
 * to open; the live control uses it when an action is refused.
 */
class RefusalText
{
    /**
     * `$action` is 'open', 'close', 'annul', 'strike', 'repeat' or 'casting';
     * `$election` lets an unreadable earlier round be named.
     */
    public function of(string $action, ElectionRoundStateException $exception, ?Election $election = null): string
    {
        $message = $exception->getMessage();
        $name = $exception instanceof ElectionCandidateBlockedException
            ? MemberProposal::authorNameOf(EinundzwanzigPleb::query()->with('profile')->find($exception->memberId))
            : null;

        return match (true) {
            $action === 'open' && str_contains($message, 'without approved candidates') => __('Dieser Wahlgang hat keine freigegebene Kandidatur. Gebt unter „Kandidaturen“ eine frei, oder streicht den Wahlgang, wenn niemand mehr antreten kann.'),
            $action === 'open' && $name !== null && str_contains($message, 'stored votes cannot be counted') => $this->unreadableBlock($name, $exception->memberId, $election),
            // Before the run-off arm, so a future message that also says "run-off" still lands here.
            $action === 'open' && $name !== null && str_contains($message, 'second ballot is owed') => __(':name gehört zu den Bestplatzierten eines früheren Wahlgangs ohne Mehrheit, und dessen Stichwahl steht noch aus. Legt zuerst die Stichwahl an.', ['name' => $name]),
            $action === 'open' && $name !== null && str_contains($message, 'also stands in the opened round') => __(':name kandidiert auch im Wahlgang „:round“, der noch nicht ausgezählt ist. Zählt ihn zuerst aus, oder zieht die Kandidatur von :name hier unter „Kandidaturen“ zurück.', ['name' => $name, 'round' => $exception->roundTitle]),
            $action === 'open' && str_contains($message, 'run-off') => __('Die Stichwahl lässt sich erst öffnen, wenn ihr Wahlgang ausgezählt ist.'),
            $action === 'open' && $name !== null && str_contains($message, 'not sealed yet') => __(':name kandidiert auch in einem früheren Wahlgang, der noch nicht ausgezählt ist. Zählt ihn zuerst aus, oder zieht die Kandidatur von :name hier unter „Kandidaturen“ zurück.', ['name' => $name]),
            $action === 'open' && $name !== null && str_contains($message, 'has been elected') => __(':name ist in einem früheren Wahlgang schon gewählt. Zieht die Kandidatur von :name hier unter „Kandidaturen“ zurück, dann lässt sich der Wahlgang öffnen. Kann danach niemand mehr antreten, streicht den Wahlgang.', ['name' => $name]),
            $action === 'open' && $name !== null && str_contains($message, 'undecided tie of the round "') => __(':name steht im Wahlgang „:round“ im Gleichstand, und der ist noch nicht entschieden. Tragt dort zuerst den Stichentscheid der/des Vorsitzenden ein, oder zieht die Kandidatur von :name hier unter „Kandidaturen“ zurück.', ['name' => $name, 'round' => $exception->roundTitle]),
            $action === 'casting' && $name !== null && str_contains($message, 'also stands in the opened round') => __(':name kandidiert auch im Wahlgang „:round“, der noch nicht ausgezählt ist. Entscheidet für eine andere Person aus dem Gleichstand, oder entscheidet, nachdem „:round“ ausgezählt ist.', ['name' => $name, 'round' => $exception->roundTitle]),
            $action === 'casting' && $name !== null => __(':name ist im Wahlgang „:round“ schon gewählt. Die/der Vorsitzende kann nur für eine andere Person aus dem Gleichstand entscheiden.', ['name' => $name, 'round' => $exception->roundTitle]),
            $action === 'open' && $name !== null && str_contains($message, 'undecided tie') => __(':name steht in einem früheren Wahlgang im Gleichstand, und der ist noch nicht entschieden. Tragt den Stichentscheid der/des Vorsitzenden ein, oder zieht die Kandidatur von :name hier unter „Kandidaturen“ zurück.', ['name' => $name]),
            $action === 'open' && str_contains($message, 'fee year') => __('Die Beitragsregel dieser Wahl braucht ein Beitragsjahr. Tragt es unter „Einstellungen“ ein.'),
            $action === 'open' => __('Dieser Wahlgang lässt sich gerade nicht öffnen. Lade die Seite neu, vielleicht hat ihn schon jemand geöffnet.'),
            $action === 'close' => __('Der Wahlgang nimmt keine Stimmen mehr an. Lade die Seite neu, vielleicht hat ihn schon jemand geschlossen.'),
            $action === 'strike' => __('Streichen geht nur bei einem Wahlgang, der nie geöffnet wurde. Lade die Seite neu, vielleicht hat ihn schon jemand geöffnet oder gestrichen.'),
            default => __('Der Wahlgang ist schon annulliert oder noch nicht geöffnet. Lade die Seite neu.'),
        };
    }

    /**
     * The mark of a round whose stored votes do not fit it, in the words of
     * the minutes: no result, the count is refused until it is verified.
     */
    public function unreadable(ElectionRound $round): string
    {
        return __('Ergebnis nicht lesbar: Die gespeicherten Stimmen passen nicht zu diesem Wahlgang. Prüfen mit „php artisan election:verify :round“.', ['round' => $round->id]);
    }

    public function unreadableRunOff(ElectionRound $round): string
    {
        return __('Für „:round“ lässt sich keine Stichwahl anlegen: Ergebnis nicht lesbar. Prüft den Wahlgang mit „php artisan election:verify :id“ oder annulliert ihn.', ['round' => $round->title, 'id' => $round->id]);
    }

    /**
     * Opening is refused because `$memberId` also stands in a sealed round of
     * this election whose stored votes cannot be counted. The exception does
     * not carry that round, so it is found here by trying the count.
     */
    private function unreadableBlock(string $name, int $memberId, ?Election $election): string
    {
        $tally = app(TallyRound::class);
        $round = $election?->rounds()
            ->whereNotNull('closed_at')->whereNull('annulled_at')
            ->whereHas('candidates', fn ($query) => $query->where('einundzwanzig_pleb_id', $memberId))
            ->orderBy('position')->get()
            ->first(function (ElectionRound $round) use ($tally): bool {
                try {
                    $tally->effective($round);
                } catch (UnexpectedValueException) {
                    return true;
                }

                return false;
            });

        return $round === null
            ? __(':name kandidiert auch in einem früheren Wahlgang, dessen Ergebnis nicht lesbar ist. Prüft ihn mit „php artisan election:verify“ oder annulliert ihn.', ['name' => $name])
            : __(':name kandidiert auch in „:round“, und dessen Ergebnis ist nicht lesbar. Prüft ihn mit „php artisan election:verify :id“ oder annulliert ihn.', ['name' => $name, 'round' => $round->title, 'id' => $round->id]);
    }
}
