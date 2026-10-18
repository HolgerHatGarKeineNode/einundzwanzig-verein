<?php

use App\Support\DescriptionImageReferences;

const UUID_A = '0b6f3c1e-8a2d-4f5b-9c7e-1d2a3b4c5d6e';
const UUID_B = 'f1e2d3c4-b5a6-4978-8a9b-0c1d2e3f4a5b';

it('finds an image on our own url', function () {
    expect(DescriptionImageReferences::extract('![Bild](https://verein.einundzwanzig.space/description-images/'.UUID_A.')'))
        ->toBe([UUID_A]);
});

it('finds an image on another host with the same path', function () {
    expect(DescriptionImageReferences::extract('![x](https://staging.example.org/description-images/'.UUID_A.')'))
        ->toBe([UUID_A]);
});

it('finds two images and lists each uuid once', function () {
    $markdown = '![Bild](https://a.test/description-images/'.UUID_A.")\n\ntext\n\n"
        .'![Bild](https://a.test/description-images/'.UUID_B.")\n\n"
        .'[again](https://a.test/description-images/'.UUID_A.')';

    expect(DescriptionImageReferences::extract($markdown))->toBe([UUID_A, UUID_B]);
});

it('finds nothing in a description without images', function (?string $markdown) {
    expect(DescriptionImageReferences::extract($markdown))->toBe([]);
})->with([
    'plain text' => 'Nur Text, kein Bild.',
    'empty' => '',
    'null' => null,
]);

it('ignores images on foreign hosts', function () {
    expect(DescriptionImageReferences::extract('![x](https://imgur.com/a/'.UUID_A.'.png) ![y](https://example.org/images/'.UUID_B.')'))
        ->toBe([]);
});

it('ignores malformed uuids', function (string $path) {
    expect(DescriptionImageReferences::extract("![x](https://a.test/description-images/{$path})"))->toBe([]);
})->with([
    'not a uuid' => 'not-a-uuid',
    'too short' => '0b6f3c1e-8a2d-4f5b-9c7e-1d2a3b4c5d6',
    'too long' => UUID_A.'f',
    'non-hex' => 'zb6f3c1e-8a2d-4f5b-9c7e-1d2a3b4c5d6e',
    'numeric id' => '42',
]);

it('returns an uppercase reference lowercased', function () {
    expect(DescriptionImageReferences::extract('![Bild](https://a.test/description-images/'.strtoupper(UUID_A).')'))
        ->toBe([UUID_A]);
});

it('removes the uploader form with its paragraph break and leaves the rest byte-identical', function () {
    $image = '![Bild](https://a.test/description-images/'.UUID_A.')';

    expect(DescriptionImageReferences::removeUploaderForm("Intro\n\n{$image}\n\nOutro", UUID_A))->toBe("Intro\n\nOutro")
        ->and(DescriptionImageReferences::removeUploaderForm("{$image}\n\nOutro", UUID_A))->toBe('Outro')
        ->and(DescriptionImageReferences::removeUploaderForm("Intro\n\n{$image}\n\n", UUID_A))->toBe("Intro\n\n")
        ->and(DescriptionImageReferences::removeUploaderForm("Text {$image} weiter", UUID_A))->toBe('Text  weiter');
});

it('removes every occurrence exactly once and keeps other images and forms', function () {
    $own = '![Bild](https://a.test/description-images/'.UUID_A.')';
    $upper = '![Bild](https://a.test/description-images/'.strtoupper(UUID_A).')';
    $other = '![Bild](https://a.test/description-images/'.UUID_B.')';
    $titled = '![Bild](https://a.test/description-images/'.UUID_A.' "Titel")';
    $alt = '![Foto](https://a.test/description-images/'.UUID_A.')';

    $markdown = "A\n\n{$own}\n\nB\n\n{$other}\n\nC\n\n{$upper}\n\nD {$titled} {$alt}";

    expect(DescriptionImageReferences::removeUploaderForm($markdown, UUID_A))
        ->toBe("A\n\nB\n\n{$other}\n\nC\n\nD {$titled} {$alt}");
});
