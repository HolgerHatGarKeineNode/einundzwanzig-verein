<?php

namespace App\Traits;

use App\Models\Profile;
use Illuminate\Support\Facades\Http;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Key\Key;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Request\Request;
use swentel\nostr\Subscription\Subscription;

trait NostrFetcherTrait
{
    /**
     * Get all NIP-05 handles for a given pubkey from nostr.json
     *
     * @param  string  $pubkey  The public key in hex format
     * @return array Array of handles associated with the pubkey
     */
    public function getNip05HandlesForPubkey(string $pubkey): array
    {
        try {
            $response = Http::get(
                'https://einundzwanzig.space/.well-known/nostr.json',
            );
            $data = $response->json();

            if (! isset($data['names'])) {
                return [];
            }

            $handles = [];
            foreach ($data['names'] as $handle => $handlePubkey) {
                if ($handlePubkey === $pubkey) {
                    $handles[] = $handle;
                }
            }

            return $handles;
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Seconds to wait for each relay's answer in fetchProfile(); null keeps
     * the vendor default of 60 s. It does not bound the connect itself:
     * swentel's Relay::connect() builds its own client with that default.
     */
    protected function profileRelayTimeout(): int|float|null
    {
        return null;
    }

    public function fetchProfile($npubs)
    {
        $hex = collect([]);
        foreach ($npubs as $item) {
            // check if $item is already a hex string
            if (preg_match('/^[0-9a-fA-F]+$/', $item)) {
                $hex->push([
                    'hex' => $item,
                    'npub' => (new Key)->convertPublicKeyToBech32($item),
                ]);

                continue;
            }
            $hex->push([
                'hex' => (new Key)->convertToHex($item),
                'npub' => $item,
            ]);
        }
        $relayUrls = config('einundzwanzig.config.profile_relays', []);

        // Leere Liste = bewusst kein Abruf. In Tests ist das der Normalfall:
        // Ein Testlauf darf keine echten Verbindungen nach draussen aufbauen,
        // und der Abruf laeuft SYNCHRON im Request — vier nicht antwortende
        // Relays kosten gemessen 21 Sekunden, pro Aufruf, auch beim zweiten Mal.
        if ($relayUrls === []) {
            return;
        }

        $subscription = new Subscription;
        $subscriptionId = $subscription->setId();

        $filter1 = new Filter;
        $filter1->setKinds([0]); // You can add multiple kind numbers
        $filter1->setAuthors($hex->pluck('hex')->toArray()); // You can add multiple author ids
        $filters = [$filter1];
        $requestMessage = new RequestMessage($subscriptionId, $filters);

        // Collect all responses from all relays
        $allResponses = collect([]);
        $timeout = $this->profileRelayTimeout();
        foreach ($relayUrls as $relayUrl) {
            $relay = new Relay($relayUrl);
            $relay->setMessage($requestMessage);
            $request = new Request($relay, $requestMessage);

            if ($timeout !== null) {
                $request->setTimeout($timeout);
            }

            try {
                $response = $request->send();
                $data = $response[$relayUrl];
                if (! empty($data)) {
                    \Log::info('Successfully fetched data from relay: '.$relayUrl);
                    $allResponses = $allResponses->concat($data);
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to fetch from relay '.$relayUrl.': '.$e->getMessage());
            }
        }

        if ($allResponses->isEmpty()) {
            \Log::warning('No data found from any relay');

            return;
        }

        // Group responses by pubkey and merge profile data
        $mergedProfiles = [];
        foreach ($allResponses as $item) {
            try {
                if (isset($item->event)) {
                    $pubkey = $item->event->pubkey;
                    $result = json_decode($item->event->content, true, 512, JSON_THROW_ON_ERROR);

                    if (! isset($mergedProfiles[$pubkey])) {
                        $mergedProfiles[$pubkey] = [
                            'pubkey' => $pubkey,
                            'name' => $result['name'] ?? null,
                            'display_name' => $result['display_name'] ?? null,
                            'picture' => $result['picture'] ?? null,
                            'banner' => $result['banner'] ?? null,
                            'website' => $result['website'] ?? null,
                            'about' => $result['about'] ?? null,
                            'nip05' => $result['nip05'] ?? null,
                            'lud16' => $result['lud16'] ?? null,
                            'lud06' => $result['lud06'] ?? null,
                            'deleted' => $result['deleted'] ?? false,
                        ];
                    } else {
                        // Merge data: keep existing non-null values, use new values if existing is null
                        $fields = ['name', 'display_name', 'picture', 'banner', 'website', 'about', 'nip05', 'lud16', 'lud06', 'deleted'];
                        foreach ($fields as $field) {
                            if (array_key_exists($field, $result)) {
                                $mergedProfiles[$pubkey][$field] = $result[$field];
                            }
                        }
                    }
                }
            } catch (\JsonException $e) {
                \Log::error('Error decoding JSON for pubkey: '.$item->event->pubkey ?? 'unknown', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Update/create profiles with merged data
        foreach ($mergedProfiles as $profileData) {
            try {
                Profile::query()->updateOrCreate(
                    ['pubkey' => $profileData['pubkey']],
                    $profileData,
                );
                \Log::info('Profile updated/created for pubkey: '.$profileData['pubkey']);
            } catch (\Exception $e) {
                \Log::error('Failed to save profile for pubkey: '.$profileData['pubkey'], [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
