<?php

namespace Database\Seeders;

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\PaymentEvent;
use Illuminate\Database\Seeder;

class PlebSeeder extends Seeder
{
    /**
     * @var array<int, array{npub:string, pubkey:string, email:string, nip05:string, status:AssociationStatus, paid_years:array<int>}>
     */
    private array $boardMembers = [
        [
            'npub' => 'npub17fqtu2mgf7zueq2kdusgzwr2lqwhgfl2scjsez77ddag2qx8vxaq3vnr8y',
            'pubkey' => 'f240be2b684f85cc81566f2081386af81d7427ea86250c8bde6b7a8500c761ba',
            'email' => 'markus@einundzwanzig.space',
            'nip05' => 'markusturm',
            'status' => AssociationStatus::HONORARY,
            'paid_years' => [2024, 2025, 2026],
            'name' => 'Markus Turm',
        ],
        [
            'npub' => 'npub1pt0kw36ue3w2g4haxq3wgm6a2fhtptmzsjlc2j2vphtcgle72qesgpjyc6',
            'pubkey' => '0adf67475ccc5ca456fd3022e46f5d526eb0af6284bf85494c0dd7847f3e5033',
            'email' => 'board1@einundzwanzig.space',
            'nip05' => 'pleb1',
            'status' => AssociationStatus::HONORARY,
            'paid_years' => [2024, 2025, 2026],
            'name' => 'Vorstand 1',
        ],
        [
            'npub' => 'npub1gvqkjccl9urg93svaw60jqkk3ux8r3ycl5t3rlvc9uzjeu0agfuss8x8qy',
            'pubkey' => '430169631f2f0682c60cebb4f902d68f0c71c498fd1711fd982f052cf1fd4279',
            'email' => 'board2@einundzwanzig.space',
            'nip05' => 'pleb2',
            'status' => AssociationStatus::HONORARY,
            'paid_years' => [2025, 2026],
            'name' => 'Vorstand 2',
        ],
        [
            'npub' => 'npub10t8npnmqhpwx9w8k232kess7gqtdlr6kqjemdzf8jnughwqd0gwsez0924',
            'pubkey' => '7acf30cf60b85c62b8f654556cc21e4016df8f5604b3b6892794f88bb80d7a1d',
            'email' => 'board3@einundzwanzig.space',
            'nip05' => 'pleb3',
            'status' => AssociationStatus::HONORARY,
            'paid_years' => [2025, 2026],
            'name' => 'Vorstand 3',
        ],
        [
            'npub' => 'npub1r8343wqpra05l3jnc4jud4xz7vlnyeslf7gfsty7ahpf92rhfmpsmqwym8',
            'pubkey' => '19e358b8011f5f4fc653c565c6d4c2f33f32661f4f90982c9eedc292a8774ec3',
            'email' => 'board4@einundzwanzig.space',
            'nip05' => 'pleb4',
            'status' => AssociationStatus::HONORARY,
            'paid_years' => [2026],
            'name' => 'Vorstand 4',
        ],
    ];

    public function run(): void
    {
        foreach ($this->boardMembers as $member) {
            $pleb = EinundzwanzigPleb::query()->create([
                'npub' => $member['npub'],
                'pubkey' => $member['pubkey'],
                'email' => $member['email'],
                'nip05_handle' => $member['nip05'],
                'application_text' => 'Ich bin Teil des Einundzwanzig Vorstands und unterstütze die Mission, Bitcoin in den deutschsprachigen Raum zu bringen.',
            ]);

            $pleb->association_status = $member['status'];
            $pleb->save();

            foreach ($member['paid_years'] as $year) {
                $pleb->paymentEvents()->create([
                    'year' => $year,
                    'amount' => 21000,
                    'paid' => true,
                    'event_id' => 'seed_'.bin2hex(random_bytes(16)),
                ]);
            }
        }

        EinundzwanzigPleb::factory()
            ->count(8)
            ->active()
            ->create()
            ->each(function (EinundzwanzigPleb $pleb): void {
                PaymentEvent::factory()
                    ->paid()
                    ->withYear((int) date('Y'))
                    ->for($pleb, 'pleb')
                    ->create();
            });

        EinundzwanzigPleb::factory()
            ->count(5)
            ->state(['association_status' => AssociationStatus::PASSIVE])
            ->create();

        EinundzwanzigPleb::factory()
            ->count(3)
            ->state([
                'association_status' => AssociationStatus::DEFAULT,
                'application_text' => 'Ich möchte Mitglied bei Einundzwanzig werden und die Bitcoin-Community im deutschsprachigen Raum mitgestalten.',
            ])
            ->create();
    }
}
