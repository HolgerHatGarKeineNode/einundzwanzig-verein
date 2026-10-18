WIP

## Working on the chat package locally

The project-support chat comes from the package
[`einundzwanzig/group`](https://github.com/HolgerHatGarKeineNode/einundzwanzig-group-package),
the same one `einundzwanzig-group` uses.

**By default this repo uses the pinned versions** — `composer.lock` for PHP,
`package.json` for the JS build — exactly like the server. The neighbour repo
moves ahead with unreleased work for its own projects; following it by default
once turned this test suite red against a version nothing here had pinned.

**To develop against the neighbour checkout, opt in** (it must sit next to this repo):

```
~/Code/
├── einundzwanzig-verein/                      ← this repo
└── einundzwanzig-group/
    └── packages/einundzwanzig-group/          ← used with GROUP_PACKAGE_LOCAL=1
```

```bash
GROUP_PACKAGE_LOCAL=1 composer install   # symlinks vendor/einundzwanzig/group
GROUP_PACKAGE_LOCAL=1 npm run dev        # Vite aliases the package's raw TS (HMR)
```

Back to the pins: `composer install` (removes the link — run it a second time
to reinstall the pinned copy), then `npm run build`, then `php artisan view:clear`
(Blade keeps compiled views of the other version otherwise).

### Shipping a package change

1. Commit and push in the package repo.
2. Point the pin in `package.json` at the new commit and run `npm install`.
3. `composer update einundzwanzig/group` to move `composer.lock`.

Both pins should name the same commit, or a deployment serves two different
versions of the package.
