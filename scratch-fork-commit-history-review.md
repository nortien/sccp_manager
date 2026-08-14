# the fork author/sccp_manager commit-history review (2026-08-14)

Follow-up to the two-round file-diff review (`scratch-the fork author-full-review.md`,
which covered `pr-17.0.1.1`'s end state only). This pass walks actual commit
history on **two** the fork author branches to recover intent/context a flat diff
can't show, per the owner's explicit follow-up request. Unlike the earlier
two rounds, findings from this pass were verified and then **applied
directly** where the fix was concrete and well-evidenced (same standard as
before: `php -l` clean, but live GUI re-verification is still owed - see
"Verification" at the end). A few findings were deliberately **not**
applied - see "Not adopting" and "Deferred" below, including one class
flagged explicitly for extra caution per today's `075df95` incident.

## Method / branch relationship (read this first)

`the fork author/sccp_manager` has two branches: `develop` (the repo's default) and
`pr-17.0.1.1` (what both prior review rounds covered). **They do not share
git history** - `git compare` between them returns `404 No common ancestor`,
and the same is true comparing `develop` against upstream
`chan-sccp/sccp_manager:develop`. Root-commit inspection confirms why:

- `pr-17.0.1.1`'s root commit is `972b213b`, dated **2017-09-12** - the
  project's real, continuous, multi-year history.
- `develop`'s root commit is `5787a6de`, dated **2026-02-13**, message "Fix
  undefined array key errors for PHP 8.2" - a **freshly-initialized orphan
  branch** (`parents: []`), not a fork/branch of anything.

Comparing file trees confirms `develop`'s root is a near-snapshot of
`pr-17.0.1.1`'s state as of ~2026-02-13: every one of 99 blob paths in
`develop`'s root commit also exists in `pr-17.0.1.1`'s HEAD (0 files unique
to develop's root), 61/99 byte-identical, the rest differing by small
amounts. In short: **the fork author squashed his work-to-date into a fresh orphan
branch around 2026-02-13, then kept working on `develop` from there**,
eventually reaching content parity with `pr-17.0.1.1`'s actual HEAD (both
reach an equivalent "Fix row background" commit around 2026-02-16) before
`develop` **continues on for three more months** (through 2026-05-17) with
~35 additional commits `pr-17.0.1.1` never received. That tail is the
genuinely new material this review focuses most of its attention on;
everything before it is already covered by the existing two-round review
(walked anyway, for revert/context signal - see below).

`pr-17.0.1.1` itself is exactly **81 commits ahead of upstream
`chan-sccp/sccp_manager:develop`, 0 behind** (clean ancestry, real PR
branch) - small enough to walk in full, done below.

## Quick index

**Applied to our working tree, `php -l` clean** (live GUI re-verification
still owed, same bar as prior rounds - see "Verification"):

| # | File | Fix | Source finding |
|---|------|-----|-----------------|
| 1 | `sccpManClasses/Sccp.class.php.v433` | SQL injection in `addDevice()` (SET-clause built via raw string interpolation of `$id` and free-text settings values) and `getDevice()` (`WHERE name = '{$id}'` string-interpolated instead of using the placeholder its own `execute()` call already assumed existed) - both replaced with `PDO::quote()`/a real `?` placeholder | §A.1 (from commit `896d4c3b`) |
| 2 | `views/form.adddevice.php` | Copy-paste bug: `$def_val['addon']` was tagged `"keyword" => 'type'` instead of `'addon'` | §A.2 (from commit `67bf686d`) |
| 3 | `install.php` (`InstallDbCreateViews`) | Added `DROP TABLE IF EXISTS sccpdeviceconfig`/`sccplineconfig` before the `DROP VIEW IF EXISTS` + `CREATE OR REPLACE VIEW` sequence, defending against the view name ever having been left as a stray table | §A.3 (from commit `559aeb36`), empirically verified safe no-op against a real view on this box's MariaDB before applying |
| 4 | `Sccp_manager.class.php` (`getCodecs()`) | Added `ilbc`, `opus`, `h263p` to the known-codec list - confirmed real, valid chan-sccp codec keywords (`src/sccp_codec.c`'s `skinny_codecs[]` table) that were simply missing, silently preventing the GUI from ever exposing them as configurable | §A.4 (from commit `ad000491`) |
| 5 | `sccpManClasses/dbinterface.class.php` | Normalized `WHERE TYPE LIKE ...` → `WHERE type LIKE ...` (two spots) for internal consistency - MySQL column names are case-insensitive regardless of `lower_case_table_names`, so this was cosmetic, not a live bug, but zero-risk | §A.5 (from commit `1cb37023`/`192e0048`) |

**Investigated and explicitly NOT applied:**

- **`Setup_RealTime()`'s `$amp_conf['AMPDB*']`-might-be-an-array hardening**
  (`install.php`, from `develop` commits `63e6b2d4`/`7d76befa`) - see §D.1.
  Flagged with extra caution per the owner's note about today's `075df95`
  incident: checked this box's actual `/etc/freepbx.conf` and found these
  keys are plain scalar strings here, and found no FreePBX mechanism that
  would make them arrays. Unconfirmed on our platform - not applying an
  unverified "value might be shaped differently than expected" fix right
  after getting burned by exactly that failure mode from the opposite
  direction (assuming array when actually scalar, vs. this case's assuming
  scalar when maybe-array). Genuinely a coin flip on which assumption is
  the risky one without concrete evidence either way, so: don't guess,
  leave it for whoever can reproduce the failure.
- **`formcreate.class.php`'s QoS hex-normalization (`normalizeQosHex()`) and
  the related `sccp-restore` JS multi-target rework** (from `develop`
  commit `3517a2b5`) - see §D.2. Real, well-motivated problem (ToS/CoS
  values coming back from chan-sccp/DB in decimal instead of hex, `"0"`
  values being silently dropped by `empty()` checks) but the fix is
  entangled in a large `formcreate.class.php` rewrite this project has
  already rejected wholesale twice (reverts the customize/restore toggle
  redesign - see round 2's "deliberately not ported" section). The
  underlying QoS-format problem is real and worth its own narrowly-scoped
  fix; this specific patch is not a safe drop-in.
- **`Sccp.class.php.v433`'s codec-collection and required-field-fallback
  logic** (also in `896d4c3b`, alongside the SQL fix that *was* applied) -
  see §D.3. More invasive behavioral change (adds default label/disallow
  values, restructures codec key detection for addon devices) that wasn't
  independently verified against our device-save flow; only the
  narrowly-scoped, obviously-safe SQL-escaping portion of this commit was
  applied.
- **UI/layout work**: the entire IED/IS form-field redesign spanning
  ~35 commits on 2026-02-18 (`645bf1fe` through `c1741feb`), all row-color/
  status-color grid styling, and all i18n batches - out of scope by the
  same standard as both prior rounds (feature/cosmetic, not bug fixes; also
  most of this lands inside `formcreate.class.php`, already excluded
  wholesale for the reason above).
- **PR #19-style menu-text changes, README/docs/build-package commits** -
  same as the earlier PR-sweep file's verdict on that class of change.

**Deferred, flagged for a dedicated look (real, plausible, not blindly
portable):**

- **Firmware/LoadImage local-file-scan fallback** (`develop` commit
  `ad000491`'s `views/advserver.model.php` changes, plus the
  `loadinformationid` DB-join and `create_SEP_XML()` XML-tag insertion) -
  this is exactly the item round 1 explicitly deferred
  ("`getSccpModelInformation()`'s firmware-file-detection rewrite... needs
  a dedicated look, not a blind port"), now in a more mature, later-dated
  form. See §D.4. Touches phone provisioning XML generation - moderate
  risk, worth a focused pass on its own.
- **The whole QoS/ToS/CoS hex-vs-decimal value-format inconsistency** this
  review surfaced across three separate commits (`bf3f9ae7` module.xml
  schema, `63e6b2d4`+ scalar-safety, `3517a2b5` `normalizeQosHex()`) - see
  §D.2. The schema-length half is already fixed on our side independently
  (see §A note under "Already fine on our side" below); the
  value-*format* half (decimal `"6"` vs hex `"0x6"` appearing
  inconsistently at runtime) is a real, recurring pain point in his commit
  history that we haven't independently investigated. Worth its own
  targeted look at what our `install.php`/`formcreate.class.php` actually
  do with these values today, not a port of his entangled fix.

**Already fine on our side, confirmed while investigating a related
finding:**

- `audio_cos`/`video_cos` VARCHAR(1)-too-narrow-for-`"0x6"` bug (his commit
  `bf3f9ae7`) - our `install.php`'s own declarative schema array (lines
  ~121-123) already creates/modifies these as `VARCHAR(11) NOT NULL default
  '0x6'`/`'0x5'`, for both fresh installs and upgrades (via the `'modify'`
  clause). **Not applying his migration-guard function** (`InstallDB_widenCosColumns()`)
  since it's redundant with what we already do more thoroughly. One loose
  end: our `module.xml`'s static `<field>` declaration for these two
  columns still says `length="1"`/decimal defaults `"6"`/`"5"`, inconsistent
  with what `install.php` actually creates - cosmetic/documentation
  mismatch only (install.php's imperative schema logic is what actually
  runs), not applied in this pass since it's zero-impact and touches a
  manifest file rather than fixing a live bug.
- The "SCCPShowSoftKeySetsComplete casing mismatch" the fork author's own
  `Technical.notes/chan-sccp-requirements.md`/`chan-sccp-verification-report.md`
  (added in his commit `c019a42e`, both Russian-language driver-contract
  audit docs) flags as a *possible* bug - checked against our actual
  current chan-sccp source and it is **not** a bug: the AMI action is
  registered under a hardcoded lowercase-k string
  (`pbx_manager_register("SCCPShowSoftkeySets", ...)`,
  `src/sccp_cli.c:4140`) completely decoupled from the `AMI_COMMAND`
  macro-local `#define` (capital-K, `src/sccp_cli.c:2407`) that only builds
  the human-readable usage text and the `"...Complete"` event string inside
  the macro-generated handler body. Our `sccp_manager` sends the action as
  lowercase-k (`Message.class.php:364`, matches) and expects the completion
  event as capital-K (`Response.class.php:348`, matches what the macro
  actually emits). Both sides already correctly aligned despite looking
  inconsistent at a glance - a good example of why this project's own rule
  ("verify against actual current source, don't trust a hypothesis") holds
  even for the *reviewer's own* speculative documentation, not just
  upstream issue reports.

---

## §A. Applied fixes - full detail

### A.1 SQL injection in `Sccp.class.php.v433` (live driver-contract file)

**Confirmed live, not dead/reference code**: this project's own
architecture notes flag `Sccp.class.php.v433` as "a versioned reference
file... check before assuming it's live." Checked:
`admin/modules/core/functions.inc/drivers/Sccp.class.php` on this box
literally is `<?php include '/var/www/html/admin/modules/sccp_manager/sccpManClasses/Sccp.class.php.v433'; ?>`
- i.e. FreePBX's actual Core Driver registration for every SCCP
extension add/edit/delete/get operation, wired up by `install.php`'s
`addDriver()`. This file is live.

**`addDevice($id, $settings)`** (used for both add and edit, per its own
code comment) built its SQL with raw interpolation, no escaping at all:

```php
// before (both $id and every settings value interpolated unescaped)
$sqlSet = "name='{$id}'";
foreach($this->data_fld as $key => $val) {
    if (!empty($settings[$val]['value'])) {
        $sqlSet .= ", {$key}='{$settings[$val]['value']}'";
    }
}
$stmt = "INSERT INTO sccpline SET {$sqlSet} ON DUPLICATE KEY UPDATE {$sqlSet}";
```

`$settings[$val]['value']` includes genuinely free-text, admin-settable
fields (label, description/`extdisplay`, etc.) - not just the numeric
extension id. An admin (or anyone with extension-edit access) typing a
single quote into a device's description field would break out of the
string literal. Fixed by building the `SET` clause with
`$this->database->quote()` on every value:

```php
$idEsc = $this->database->quote($id, \PDO::PARAM_STR);
$setParts = array("name={$idEsc}");
foreach($this->data_fld as $key => $val) {
    $v = $settings[$val]['value'] ?? '';
    if ($v !== '' && $v !== null) {
        $setParts[] = $key . '=' . $this->database->quote((string) $v, \PDO::PARAM_STR);
    }
}
$sqlSet = implode(', ', $setParts);
```

(Also folded in the same `!empty()` → `$v !== '' && $v !== null` change
the fork author made in the same spot - `empty()` treats the string `"0"` as empty,
so any field whose valid value is literally `"0"` was being silently
dropped from the SQL entirely, not just failing to escape. Real, separate,
same-commit bug.)

**`getDevice($id)`** had an even stranger version of the same problem - the
query string had `$id` raw-interpolated into a literal (`WHERE name =
'{$id}'`) **and** a `$sth->execute(array($id))` call two lines later that
had nothing to actually bind to (no `?` placeholder existed). Fixed by
adding the missing placeholder:

```php
$sql .= " FROM sccpline WHERE name = ?";   // was: " FROM sccpline WHERE name = '{$id}'"
$sth = $this->database->prepare($sql);
...
$sth->execute(array($id));   // unchanged - now actually does something
```

This mirrors the exact SQL-injection pattern already found and fixed once
in round 1 (`getDefaultLine`'s `WHERE ref = '{$data['id']}'`) - same file
family, same underlying discipline gap, previously unnoticed because it's
in a *different* function (`getDevice`/`addDevice` vs `getDefaultLine`) in
a file whose own docs warn "check before assuming it's live."

The fork author fixed this too (commit `896d4c3b`, "Extensions (Line): driver
codec/devinfo fix, SQL escaping, required fields fallback") - our fix
follows the same `PDO::quote()` approach for the SET-clause portion (a
prepared-statement placeholder doesn't cleanly fit a dynamic-column-count
SET clause, `quote()` is the idiomatic PDO answer here), independently
re-derived and re-verified rather than copy-pasted, matching this project's
established practice.

### A.2 `form.adddevice.php` addon-keyword copy-paste bug

```php
// before
$def_val['addon'] = array("keyword" => 'type', "data" => $_REQUEST['addon'], "seq" => "99");
// after
$def_val['addon'] = array("keyword" => 'addon', "data" => $_REQUEST['addon'], "seq" => "99");
```

From the fork author's commit `67bf686d` ("Fix critical device save issues (addon
mapping, zero-value persistence)"). Confirmed present verbatim in our
current file before the fix. Low severity on its own (affects only the
"adding a device that's connected but not yet in the DB, with an addon
module pre-selected via `$_REQUEST`" path defaulting correctly), but a
clean, obviously-correct, zero-risk one-line fix.

### A.3 `install.php`: `DROP TABLE IF EXISTS` before view (re)creation

The fork author's commit `559aeb36` (`pr-17.0.1.1`) / `bcfce8f7` (`develop`, same
change) adds a `DROP TABLE IF EXISTS sccpdeviceconfig`/`sccplineconfig`
step before the existing `DROP VIEW IF EXISTS` + `CREATE OR REPLACE VIEW`
sequence, with a README note explaining the motivating scenario: if either
view name was ever accidentally created as a real TABLE (his note says "by
an old script"), `CREATE OR REPLACE VIEW` on that name would fail, and
chan_sccp's realtime lookup would silently see 0 rows and reject every
device with a confusing "device unknown" registration error - a real
symptom that would be hard to diagnose from the registration-reject side
alone.

**Verified empirically before applying** (this project's stated "always
verify, never guess" standard applied literally): created a throwaway
table + view with the same name pattern in a scratch database on this
box's actual MariaDB (`10.11.18-MariaDB`), then ran `DROP TABLE IF EXISTS`
against the view name:

```sql
CREATE VIEW zz_testview AS SELECT * FROM zz_base;
DROP TABLE IF EXISTS zz_testview;
-- no error, view survives untouched (confirmed via SHOW FULL TABLES)
```

So the addition is a safe no-op in the normal case (object is really a
view) and a genuine fix in the edge case (object is really a stray table) -
strictly better, no downside found. Applied to both
`InstallDbCreateViews()` blocks (`sccpdeviceconfig` and `sccplineconfig`).

### A.4 Missing codec keywords in `getCodecs()`

```php
// before
array('alaw', 'ulaw', 'g722', 'g723', 'g726', 'g729', 'gsm', 'h264', 'h263', 'h261')
// after
array('alaw', 'ulaw', 'g722', 'g723', 'g726', 'g729', 'gsm', 'ilbc', 'opus', 'h264', 'h263', 'h263p', 'h261')
```

From `develop` commit `ad000491`. Verified against chan-sccp's actual
`src/sccp_codec.c` `skinny_codecs[]` table (byte-identical between our fork
and the fork author's, confirmed in the file-diff review) that `ilbc`, `opus`, and
`h263p` are real, distinct, driver-supported codec keywords - `h263p` in
particular needed checking since chan-sccp has *two* H.263-family rows
sharing one internal key (`"h263"`) but distinct config-string keys
(`"h263"` vs `"h263p"`); confirmed `h263p` is the correct config-string
identifier for the H.263+ entry, not a typo. Without these, the GUI could
never expose these codecs as configurable even on a driver build that
supports them - a real, if modest, feature gap rather than a crash-class
bug.

### A.5 `dbinterface.class.php`: `TYPE` → `type` casing consistency

Cosmetic only - verified MySQL/MariaDB column-name matching is
case-insensitive regardless of the `lower_case_table_names` setting (that
setting only affects *table* names on case-sensitive filesystems, not
column names, which follow a fixed case-insensitive collation). So this
was never a live functional bug on either side, just an internal
inconsistency (two call sites in the same function already used lowercase
`type`, two others still used `TYPE`). Normalized to lowercase for
consistency, zero functional risk.

---

## §B. `pr-17.0.1.1`'s 81-commit walk - context on already-reviewed content

Since this branch was already covered end-to-end at the file level, this
walk focused on **why** things changed, specifically watching for
reverts/back-and-forth that a flat diff would hide. One found:

```
645bf1fe 2026-02-16 UI: align form columns (label/value/actions), section and help panel styles, sccp-warning-banner class
d8dff1be 2026-02-16 Revert "UI: align form columns (label/value/actions), section and help panel styles, sccp-warning-banner class"
```

A same-day try-then-revert on a CSS/layout change. Net effect at HEAD is
correctly "no change" (which the file-diff review would have already
reflected correctly, since it compares end states) - noted here only as
confirmation that the fork author does revert things that don't work out, which is
useful context for weighing confidence in *other*, non-reverted UI changes
from the same period (i.e. the file-diff review's blanket "not
adopting - cosmetic" verdict on the surrounding UI work doesn't need
revisiting; if anything this shows some of it was already self-corrected).

The rest of the 81 commits are the same content already itemized in the
two-round file-diff review (PHP 8.2 compatibility sweep, the SQL-injection
fix in `getDefaultLine`, the audio_cos/video_cos widening, the
Array-to-string-conversion hardening pass across `install.php`, the
row-color/status UI work, i18n) - not re-itemized here to avoid duplicating
that file. The Array-to-string-conversion commits are worth calling out as
a *pattern*, though: they arrived as five separate, incrementally-refined
commits over one day (`024c1319` → `c6c561f4` → `55ca6f3f` → `fba09d82` →
`5519038f`), each one generalizing/fixing gaps in the previous one's
approach (a narrow inline fix, then a second field gets the same treatment,
then a proper `is_array()`-aware helper function replaces the ad-hoc
casts). This iterative-refinement shape recurs in the `develop`-only tail
too (§C, `Setup_RealTime`'s two-commit AMPDBNAME fix) - useful to know this
is the fork author's normal working style (fix narrowly, then generalize once the
gap is noticed), not evidence any specific fix is unreliable.

## §C. `develop`'s unique tail (2026-02-20 through 2026-05-17) - full list

The ~35 commits after `pr-17.0.1.1`'s content is exhausted, in order (this
is genuinely new ground, not covered by either prior round):

```
c6eab93e 2026-02-20 sccp.conf: default allow=alaw, avoid empty allow/disallow for Asterisk 22
2f2e91df 2026-02-21 SCCP codec: default allow=alaw for lines, normalize empty allow in DB
896d4c3b 2026-02-21 Extensions (Line): driver codec/devinfo fix, SQL escaping, required fields fallback   [A.1 applied from here]
b86c209b 2026-03-07 Stabilize SCCP settings flow and harden PHP 8.2/db paths
a2770f48 2026-03-07 Add downloadable module package and refresh README
67bf686d 2026-03-07 Fix critical device save issues (addon mapping, zero-value persistence)               [A.2 applied from here]
3517a2b5 2026-03-07 Fix QoS defaults UX and normalize ToS/CoS values to hex                                [D.2, deferred]
34d2dd11 2026-03-07 Initialize checked default buttons on page load
ad000491 2026-03-08 fix(firmware): stabilize model load image selection and SEP loadInformation generation [A.4 applied, D.4 deferred]
2292b27a 2026-04-15 Fix SCCP tab persistence for extension settings
ebb15dfc 2026-04-16 Save SCCP vmnum/trnsfvm fields in core driver
8f867e7b 2026-04-16 Organize ops docs and clean local temp artifacts
6f62c385 2026-04-17 docs: note SCCP persistence fix
867d0722 2026-04-17 build: refresh module package
6d68e233 2026-04-17 docs: point README install links to fork
39d5aacd 2026-04-17 build: refresh module package after README link fix
d4b4b9ea 2026-04-17 docs: point chan-sccp links to working fork
c251904e 2026-04-17 docs: simplify README for fork users
fba69ef0 2026-04-17 docs: remove chan-sccp URL clutter
cb1db29e 2026-04-17 docs: add README language switch
2a9f5b0e 2026-04-17 build: refresh package after language switch
8a865f1a 2026-04-17 docs: add language switch links
da16e963 2026-04-17 docs: broaden firmware menu file detection
ae37749c 2026-04-19 fix: preserve hint speed dials in button form
14610e0c 2026-04-19 fix: guard empty button options in form buttons
4a178e82 2026-04-19 fix: guard null button names in form buttons
4b753621 2026-04-19 fix: harden AMI helpers against null values
7377c9cc 2026-04-19 build: refresh module package after test verification
18153cec 2026-04-19 fix: initialize unknown AMI events
837e1857 2026-04-19 fix: use instanceof for unknown AMI events
5f9c8a07 2026-04-19 fix: use addon-specific load information in XML
684aa161 2026-04-19 fix: harden timezone and AMI handler paths
f963189c 2026-04-20 fix: make hint button toggle checkbox
bf6f3608 2026-04-22 docs: improve Russian README wording
bff49433 2026-04-22 docs: improve Russian README translations
51358840 2026-04-24 fix: preserve BLF hint context in buttons
547422ba 2026-04-25 build: refresh module package
cf957b15 2026-05-17 fix: correct SIP locale lookup in XML
fe56e8dd 2026-05-17 cleanup: remove dead config and escape hardware links
200a8869 2026-05-17 cleanup: tighten dbinterface and config metadata
```

The April 19-24 "fix:" batch (button/AMI/timezone/BLF hardening,
`ae37749c` through `51358840`) was triaged by message + a light diff skim
only, **not individually deep-verified against our current source** - real
gap, explicitly flagged (same discipline as the chan-sccp issue-sweep's own
"metadata-only triage" caveat). These read as plausible, narrowly-scoped
null-guard/edge-case fixes in the same spirit as this project's own
"unguarded reads on sparse settings data" bug pattern, worth a dedicated
follow-up pass rather than being folded into this one given time spent
already on the higher-signal items above. `cf957b15`/`fe56e8dd`/`200a8869`
(the May 2026 tail) likewise not individually diffed - most recent, smallest
commits, lowest risk of being missed entirely if left for later.

---

## §D. Deferred / not adopting - full detail

### D.1 `Setup_RealTime()` scalar-safety hardening - unconfirmed, not applied

```php
// develop 63e6b2d4, first attempt:
$def_bd_section = is_array($amp_conf['AMPDBNAME'] ?? null) ? (string)reset($amp_conf['AMPDBNAME']) : (string)($amp_conf['AMPDBNAME'] ?? '');
// develop 7d76befa, generalized one commit later into a real helper:
$def_bd_config = array(
    'dbhost' => _sccp_install_scalar_str($amp_conf['AMPDBHOST'] ?? null),
    'dbname' => _sccp_install_scalar_str($amp_conf['AMPDBNAME'] ?? null),
    'dbuser' => _sccp_install_scalar_str($amp_conf['AMPDBUSER'] ?? null),
    'dbpass' => _sccp_install_scalar_str($amp_conf['AMPDBPASS'] ?? null),
    ...
);
```

Interesting independently for *why* it evolved this way: the first attempt
still wasn't fully safe - a bare `(string)($x ?? '')` cast on a genuinely
array-shaped value fatals with "Array to string conversion" under FreePBX's
strict error handler (the exact same class of fatal this project's own
`array_diff_assoc()` bug pattern documents) - so a naive string-cast
"defense" doesn't actually defend against the thing it's defending against,
and the fork author had to build a real `is_array()`-aware helper to safely degrade
instead. That's a genuinely useful, independently-arrived-at parallel to
the caution in this repo's "Known bug pattern: `escapeHtml()` on a value
that isn't actually a string" section - **assuming a value's shape without
verifying is risky in both directions**, whether you assume scalar-when-
maybe-array or array-when-actually-scalar.

**Checked this box's actual FreePBX config** (`/etc/freepbx.conf`):
`$amp_conf["AMPDBNAME"]` etc. are plain scalar strings here, set directly.
No FreePBX mechanism was found (in the time available) that would produce
an array for these specific keys on a standard install. Given no concrete
reproduction and the owner's explicit caution about applying unverified
"value might be shaped differently" fixes right after `075df95`, **this was
deliberately not applied**. If a future report or repro ever shows
`AMPDBNAME`/`AMPDBHOST`/etc. actually resolving to an array on some real
config (clustered/replicated DB setups seem the most plausible candidate,
though unconfirmed), this is exactly the fix to reach for - re-verify
first, the same way this note was reached.

### D.2 QoS/ToS/CoS hex-vs-decimal value format - real pattern, not ported

Across three commits (`bf3f9ae7`, `63e6b2d4`-family, `3517a2b5`), the fork author's
history shows a recurring, evidently-real problem: ToS/CoS values coming
back from chan-sccp or the DB sometimes appear in decimal (`4`/`6`/`5`)
instead of the hex format (`0x68`/`0xB8`/`0x88`) the config schema expects,
and his `normalizeQosHex()` (commit `3517a2b5`) is a fairly involved
attempt at normalizing this consistently across `sccp_tos`/`sccp_cos`/
`audio_tos`/`audio_cos`/`video_tos`/`video_cos` in the form-rendering layer,
plus a comment noting he's seen "bad chan-sccp/runtime defaults... as
4/6/5 instead of 0x68/0xB8/0x88" - i.e. this isn't speculative, he's
observed it happening. The schema-width half of this problem (§A note
under "Already fine on our side") is already handled on our side; this
runtime-value-format half is not something this review independently
reproduced or investigated against our own code, and the fix as written
lives inside the large `formcreate.class.php` file already excluded
wholesale (see round 2's rationale). Recommend a dedicated, narrowly-scoped
look at whether our own `extconfigs.class.php`/`formcreate.class.php`
QoS-field handling has the same decimal/hex inconsistency, independent of
porting his specific patch.

### D.3 `Sccp.class.php.v433`'s codec-collection/required-fields logic

The same commit that contained the SQL-injection fix applied in §A.1 also
restructures how `addDevice()`/the line-save `Sccp` driver class collects
codec selections (handling both `codec_*` and `devinfo_codec_*` /
`devinfo_sccp_codec` key shapes for addon-module devices) and adds
"required field" fallback defaults (label defaults to the extension id,
`disallow` defaults to `'all'` if unset). This is a real behavioral change,
not obviously a bug fix - could be masking a genuine upstream gap in how
addon-device codec forms submit their data, or could just be defensive
padding. Not independently verified against our own current addon-device
save flow, so **not applied** - only the narrowly-scoped, unambiguously-safe
SQL-escaping portion of this commit was pulled out and applied on its own.

### D.4 Firmware LoadImage local-file-scan fallback

`develop` commit `ad000491` adds a `views/advserver.model.php` fallback
that scans the actual TFTP firmware directory tree
(`tftp_firmware_path`/`tftp_path/firmware`) for `.loads` files per model
subdirectory, merging them into the "Load Image" dropdown alongside
whatever `masterFilesStructure.xml` already provides - directly the kind of
rewrite round 1 flagged as a deferred item ("plausibly fixing real spurious
'firmware not found' warnings, but it's TFTP-layout-dependent and risks the
opposite failure... needs a dedicated look, not a blind port"). Same
caution still applies, now with a more mature reference implementation
available if/when that dedicated look happens. Also bundled in the same
commit: a `loadinformationid` DB-join (`dbinterface.class.php`) and a
`create_SEP_XML()` addition that writes a model-specific firmware XML tag
(e.g. `loadInformation437` for a Cisco 7975) into generated phone
provisioning XML - this touches what's actually served to physical phones,
moderate risk, not applied without independent testing against our own
on-disk firmware layout.

---

## Verification

`php -l` clean on all 5 changed files (`Sccp.class.php.v433`,
`form.adddevice.php`, `install.php`, `Sccp_manager.class.php`,
`dbinterface.class.php`). **Live GUI/AJAX re-verification not yet done for
this round** - same explicit caveat both prior rounds used: do this before
considering this round fully closed out (exercise device add/edit through
the GUI to confirm `Sccp.class.php.v433`'s SQL-escaping change round-trips
correctly, reinstall to confirm the `DROP TABLE IF EXISTS` addition doesn't
break a normal install, and check the codec-selection UI shows the three
new entries).
