# timspb/sccp_manager fork review — remaining 31 files

Reviewed against our `stable` branch (live source at
`/var/www/html/admin/modules/sccp_manager`), commit `83b3c43` HEAD at the time
of this review (2026-08-14). timspb's fork fetched from
`https://raw.githubusercontent.com/timspb/sccp_manager/pr-17.0.1.1/<path>`.

This continues the earlier review that covered 8 files (`dbinterface.class.php`,
`ajaxHelper.php`, `xmlinterface.class.php`, `install.php`, `uninstall.php`,
`aminterface.class.php`, `form.buttons.php`, `Event.class.php`) — those are
**not** revisited here.

Method: `diff -u` of each file against timspb's version, then for every
non-cosmetic difference, traced the actual call chain in our live source to
establish whether it's reachable, and for the more serious claims, wrote small
standalone PHP reproductions run against the box's actual PHP 8.2.33 with a
`set_error_handler(..., E_ALL)` that converts warnings/deprecations to
`ErrorException` — replicating the handler FreePBX's `admin/bootstrap.php`
installs, which is what turns an ordinary "Undefined array key" notice into a
fatal crash in this codebase (see CLAUDE.md, "Known bug pattern: unguarded
reads on sparse settings data"). Verified live DB state (`sccpsettings` table)
via `mysql` with the credentials in `/etc/freepbx.conf` where relevant.

12 of the 31 files are **byte-identical** to our version: `page.sccp_adv.php`,
`page.sccp_phone.php`, `page.sccpsettings.php`, `views/advserver.dialtemplate.php`,
`views/form.addruser.php`, `views/form.devadvanced.php`, `views/formShowError.php`,
`views/formShowSysDefs.php`, `views/hardware.rnav.php`, `views/server.advanced.php`,
`views/server.datetime.php`, `views/server.url.php`. No action, not discussed further.

---

## Quick index of (A) findings (real bugs/improvements worth porting)

1. `Response.class.php::isSuccess()` / `getMessage()` / `getClosingEvent()` / `getCountOfEvents()` — unguarded AMI-response key reads feed `stristr()`/`strtok()`/`count()` with `null`, fatal under PHP 8.2 + FreePBX's strict handler. `isSuccess()` runs in the constructor of **every** AMI Response object.
2. `Response.class.php::Table2Array()` — unguarded `foreach` over a table's `Entries` fatal-crashes when an AMI table response has zero rows (e.g. empty SoftKeySets/Devices list).
3. `Response.class.php::SCCPShowDevice_Response::getResult()` — unguarded `strtok($result['skinnyphonetype'], ' ')` fatal-crashes for devices lacking that AMI field (e.g. `cisco-sip` hybrid devices).
4. `Response.class.php::Command_Response::__construct()` — unguarded `$content[1]` on a colon-less AMI "Command" output line. Lower severity/narrower.
5. `helperFunctions.php::initialiseConfInit()` — unguarded `$read_config['general']` + `foreach` on a possibly-non-array `getConfig('sccp.conf')` result; called from `initializeSccpPath()`, which runs in the `Sccp_manager` constructor, i.e. on every page load.
6. `helperFunctions.php::getFileListFromProvisioner()` — overwrites a known-good `masterFilesStructure.xml` with whatever `file_get_contents()` returns, with **no validation it's actually XML**. A transient GitHub failure/rate-limit page/DNS hijack silently corrupts the file.
7. `views/advserver.model.php` — unguarded `simplexml_load_file(...)->xpath(...)` chain and unguarded `$firmwareDir[0]`; fatal-crashes "Advanced Server → Model Information" the moment #6 has ever corrupted the file (empirically verified both crash modes).
8. `helperFunctions.php::saveXml()` — silently swallows XML-save failures (e.g. wrong ownership after manual edits — a documented recurring issue in this project's own CLAUDE.md).
9. `helperFunctions.php::getIpInformation()` — unguarded `$vals[3]` etc. after `preg_split()` on `ip addr` output; feeds `createDefaultSccpXml()`, a mainline provisioning path.
10. `views/server.info.php` — a cluster of unguarded array reads (`$driver['sccp']`, `$core[...]` x4, `$tftpInfo[1]` used outside its own guard, `explode(...)[3]`, `$mysql_info['Value']`, `$cisco_tz['offset']`) that can fatal-crash the one diagnostic page admins reach for precisely when something is already broken.
11. `sccpManClasses/extconfigs.class.php::updateTftpStructure()` — defensive `?? ''`/`?? 'off'` reads. Lower confidence: I could **not** prove this is currently reachable (see write-up), but it's cheap, consistent with our own established pattern, and worth doing as insurance.
12. `Sccp_manager.class.php::__construct()` — wrapping the whole constructor body in try/catch that logs + records `class_error` + rethrows, rather than an uncaught fatal. Doesn't change behavior, adds real observability given how many "fatal on sparse data" paths exist across this codebase.
13. `Sccp_manager.class.php::initializeSccpPath()` — replaces blank-string path fallbacks with sensible `/tftpboot`-relative defaults, so a missing setting degrades to "reasonable path" rather than a path that resolves to filesystem root (worse than a crash: a silent wrong-location read/write/mkdir risk).
14. `Sccp_manager.class.php` — pervasive `?? ''`/`isset()` guards through `getPhoneButtons()`, `createDefaultSccpXml()`/`createSccpDeviceXML()`, `getSccpModelInformation()`, `getHintInformation()`. Same established pattern as items above; recommend a general sweep.
15. Missing output escaping (defense-in-depth, not crash) in `views/server.codec.php`, `views/form.adddevice.php`, `views/server.info.php`, `sccpManClasses/formcreate.class.php` (`<option>` values) — admin-configurable/AMI-derived data echoed straight into HTML, inconsistent with the `htmlspecialchars()` pattern already used elsewhere in `formcreate.class.php` and in the already-fixed `ajaxHelper.php`.

**Do NOT port** (would reintroduce bugs we already fixed — see full write-ups):
- `Sccp_manager.class.php::saveSccpSettings()` reverting to `write(...,'replace')` (truncate+reinsert every load) — exactly what commit `96acb02` fixed.
- `sccpManClasses/formcreate.class.php` as a whole — reverts to the customize/restore toggle UI that our CLAUDE.md documents as "silently froze chan-sccp defaults into the DB on any save," which our documented Bootstrap-4 rework deliberately replaced. Also uses Bootstrap 3 `glyphicon` classes inconsistent with FreePBX 17's Font Awesome, matching the "icons render as nothing" bug that same rework fixed.
- `Response.class.php::SCCPJSON_Response::getResult()` still reads `getKey('JSON')` — the exact key-name typo our CLAUDE.md documents as found and fixed (should be `'JSONRAW'`). We're already ahead here.
- `extconfigs.class.php`'s dropped `ru_RU` locale entry — we have it, they don't; not a bug on our side.

---

## `sccpManClasses/amInterfaceClasses/Response.class.php` (424 diff lines)

### (A) `isSuccess()` / `getMessage()` / `getClosingEvent()` / `getCountOfEvents()` — unguarded AMI key reads, fatal under strict handler

Current code (`sccpManClasses/amInterfaceClasses/Response.class.php`):

```php
// line 20-27
public function __construct($rawContent)
{
    parent::__construct($rawContent);
    $this->_events = array();
    // this logic is false - even if we have an error, we will not get anymore data, so is completed.
    $this->_completed = $this->isSuccess();
}
...
// line 42 getClosingEvent()
public function getClosingEvent() {
    return $this->_events['ClosingEvent'];
}
// line 48 getCountOfEvents()
public function getCountOfEvents() {
    return count($this->_events);
}
// line 52 isSuccess()
public function isSuccess()
{
    // returns true if response message does not contain error
    return stristr($this->getKey('Response'), 'Error') === false;
}
// line 65 getMessage()
public function getMessage()
{
    return $this->getKey('Message');
}
```

`getKey()` (`Message.class.php:99`) returns `null`, not `''`, when the key was
never set:

```php
public function getKey($key)
{
    $key = strtolower($key);
    if (!isset($this->keys[$key])) {
        return null;
    }
    return $this->keys[$key];
}
```

So if the raw AMI text this `Response` is built from doesn't contain a
`Response:` line (malformed/truncated AMI read, non-standard chan-sccp text,
a raw Event routed through a Response subclass, etc.), `isSuccess()` calls
`stristr(null, 'Error')`.

I verified empirically on this box (PHP 8.2.33, the box's actual version)
with a `set_error_handler` that mimics FreePBX's `admin/bootstrap.php`
(`set_error_handler(..., E_ALL)` converting warnings/deprecations to
`ErrorException`, per CLAUDE.md's documented pattern):

```
--- stristr(null, 'Error') under strict handler ---
CAUGHT: ErrorException: stristr(): Passing null to parameter #1 ($haystack) of type string is deprecated

--- strtok(null, ' ') under strict handler ---
CAUGHT: ErrorException: strtok(): Passing null to parameter #1 ($string) of type string is deprecated

--- count(null) (TypeError, independent of handler, always fatal in PHP 8+) ---
CAUGHT: TypeError: count(): Argument #1 ($value) must be of type Countable|array, null given
```

**Reachability**: `$this->_completed = $this->isSuccess();` is in the base
`Response` constructor — every single AMI response class in this file
(`GenericResponse`, `Generic_Response`, `Command_Response`,
`SCCPJSON_Response`, `SCCPGeneric_Response`, `SCCPShowSoftkeySets_Response`,
`SCCPShowDevices_Response`, `SCCPShowDevice_Response`,
`ExtensionStateList_Response`) extends `Response` and runs this on every
construction, i.e. on every single AMI call this module makes. This is the
single highest-reachability finding in this whole review. `getCountOfEvents()`
is called right after `getClosingEvent()` in `aminterface.class.php:194-196`,
on the "did we receive everything" check for list-type AMI responses.

timspb's fix:

```php
public function getClosingEvent() {
    return $this->_events['ClosingEvent'] ?? null;
}
public function getCountOfEvents() {
    return is_array($this->_events) ? count($this->_events) : 0;
}
public function isSuccess()
{
    return stristr((string)($this->getKey('Response') ?? ''), 'Error') === false;
}
public function getMessage()
{
    return $this->getKey('Message') ?? '';
}
```

**Proposed fix** (same idea, matching our code style):
```php
public function getClosingEvent() {
    return $this->_events['ClosingEvent'] ?? null;
}
public function getCountOfEvents() {
    return is_array($this->_events) ? count($this->_events) : 0;
}
public function isSuccess()
{
    return stristr((string) ($this->getKey('Response') ?? ''), 'Error') === false;
}
public function getMessage()
{
    return $this->getKey('Message') ?? '';
}
```

Note `isList()` (line 58) does **not** need this treatment —
`$this->getKey('EventList') === 'start'` is a strict comparison, and PHP
doesn't warn on strict comparisons of mismatched types (verified: no warning
either way). timspb changed it anyway (`?? ''`); harmless but unnecessary.

### (A) `Table2Array()` — unguarded foreach on possibly-absent table entries

Current code (line 319):

```php
public function Table2Array( $tablename )
{
    $result =array();
    if (empty($tablename) || !is_array($this->_tables)) {
        return $result;
    }
    foreach ($this->_tables[$tablename]['Entries'] as $trow) {
        $result[]= $trow->getKeys();
    }
    return $result;
}
```

If `$tablename` was never populated in `$this->_tables` (e.g. a legitimately
empty AMI table response — no SoftKeySets defined, a query returning zero
rows), `$this->_tables[$tablename]['Entries']` is undefined. `foreach` on an
undefined nested array key throws under the strict handler (verified: yields
`ErrorException: Undefined array key "..."`, the same "sparse data + strict
handler = fatal" pattern documented in CLAUDE.md, just off the AMI response
path instead of DB settings). Called from `ConvertTableData()`
(`Response.class.php:261`) which `SCCPGeneric_Response`'s various subclasses
use for `getResult()`.

**Proposed fix**:
```php
public function Table2Array( $tablename )
{
    $result = array();
    if (empty($tablename) || !is_array($this->_tables)) {
        return $result;
    }
    if (!isset($this->_tables[$tablename]['Entries']) || !is_array($this->_tables[$tablename]['Entries'])) {
        return $result;
    }
    foreach ($this->_tables[$tablename]['Entries'] as $trow) {
        if (is_object($trow) && method_exists($trow, 'getKeys')) {
            $result[] = $trow->getKeys();
        }
    }
    return $result;
}
```

(Adopting only the guard, not timspb's `is_object()/method_exists()` belt —
though that's harmless too and cheap to include.)

### (A) `SCCPShowDevice_Response::getResult()` — unguarded `strtok()` on possibly-missing device field

Current code (line ~413):

```php
$result['SCCP_Vendor'] = array('vendor' => strtok($result['skinnyphonetype'], ' '), 'model' => strtok('('),
                               'model_id' => strtok(')'), 'vendor_addon' => strtok($result['configphonetype'], ' '),
                               'model_addon' => strtok(' '));
```

Same `strtok(null, ...)` deprecation-turned-fatal issue as above if
`skinnyphonetype`/`configphonetype` aren't present in the parsed AMI result —
plausible for the `cisco-sip` hybrid device type this module explicitly
supports (see `views/form.adddevice.php`'s `tech_hardware=cisco-sip` branch),
since those aren't native SCCP devices and may not populate every field a
genuine SCCP phone would.

**Proposed fix**: wrap both reads: `strtok((string)($result['skinnyphonetype'] ?? ''), ' ')` and `strtok((string)($result['configphonetype'] ?? ''), ' ')`.

### (A, lower severity) `Command_Response::__construct()` — unguarded `$content[1]`

Current code (line 122):
```php
$content = explode(':', $line);
if (is_array($content)) {
    switch (strtolower($content[0])) {
        case 'actionid':
            $this->_temptable['ActionID'] = trim($content[1]);
            break;
        ...
```

`explode()` always returns an array, so `is_array($content)` is always true
(dead check) — the real gap is `$content[1]`: if `$line` has no colon at all,
`explode(':', $line)` returns a one-element array and `$content[1]` is
undefined. `Command_Response` parses raw Asterisk CLI passthrough text (via
AMI `Command` action), which is far less structured than normal AMI
`key: value` responses, so a stray colon-less line landing on one of these
`case` labels (e.g. a CLI line that's literally the word "Output") is a real,
if narrow, possibility.

**Proposed fix**: guard with `count($content) >= 2` before the switch, and
`$content[1] ?? ''` at each use, matching timspb.

### (B)/(C) — not adopting
- `#[\AllowDynamicProperties]` attribute removals across all classes in this
  file, replaced by explicit property declarations (`eventListIsCompleted`,
  `eventListEndEvent`) — we already keep the attribute on every class, which
  covers this more broadly. No functional difference either way; not worth
  the churn.
- `addEvent()`'s big rewrite (explicit `eventListEndEvent` check,
  `TableEntries` count comparison) is **entangled with a pile of
  `error_log(...)` debug calls** ("SoftKeySets debug: ...", "SoftKeySets
  count mismatch...", "addEvent: Setting ClosingEvent to...") that read as
  leftover troubleshooting instrumentation, not production code — adopting
  this wholesale would spam the Asterisk/FreePBX log on every SoftKeySets AMI
  response, which happens routinely (every device registration/config
  refresh). The one genuinely reusable idea buried in there — guard
  `count($this->_tables[$tableName]['Entries'] ?? [])` before comparing
  against `TableEntries` — is effectively already covered by the
  `Table2Array()` fix above, since that's the function actually consuming
  this data downstream. Not porting `addEvent()` as-is.
- `SCCPShowSoftkeySets_Response::getResult()` is fully rewritten with a
  custom grouping algorithm plus a hardcoded `mapModeToKey()` table for mode
  names like `HOLDCONF`/`INUSEHINT`/`ONHOOKSTEALABLE`, again wrapped in
  `error_log()` debug calls and comments like "For debugging, let's be more
  lenient". This is clearly timspb working around something specific to
  *their* chan-sccp fork's exact SoftKeySets AMI event shape — not something
  to blind-port without independently confirming our own chan-sccp emits a
  materially different SoftKeySets format that needs it. Not adopting.
- `ConvertTableData()` / `ConvertTableDataUsingReference`-style helper (lines
  ~253-274 of the diff): `?? ''` guards on `$_row[$_fid]`/`$tmp_result[$_fid]`
  etc. — same established pattern as everything else in this review, fine to
  adopt as part of a general sweep, not severe enough to write up
  individually.
- `sanitizeInput()` param rename `$prefered_type` → `$preferred_type` —
  cosmetic (positional-only parameter, no external callers reference it by
  name).
- `sanitizeInput()`'s new `elseif (is_string($value) && strlen(trim($value)) > 0)` branch
  (strips bytes outside `0x20-0x7E`/LF/CR/tab instead of `htmlspecialchars()`-encoding)
  is a **trade-off, not a clean win**: it doesn't actually close any gap (CR/LF
  pass through unstripped in *both* versions, so no CRLF/AMI-header-injection
  fix is happening here despite the "sanitize" framing) and would mangle
  legitimate non-ASCII data (accented names, non-English hint labels) by
  silently stripping it byte-by-byte. Not adopting.
- **Do NOT port**: `SCCPJSON_Response::getResult()` still reads
  `getKey('JSON')` in timspb's fork:
  ```php
  $jsonData = $this->getKey('JSON') ?? '';
  if (($json = json_decode($jsonData, true)) != false) {
  ```
  Our current code already reads `getKey('JSONRAW')` — this is the exact
  key-name typo bug our own CLAUDE.md documents as found and fixed
  ("`getVariable()` stored the parsed AMI JSON under `'JSONRAW'`... plain
  key-name typo... silently produced the 'driver not found' symptom"). We are
  already correct; timspb's fork still has the bug we already fixed. This is
  exactly the kind of thing the task warned about — don't trust diff
  direction blindly.

---

## `sccpManTraits/helperFunctions.php` (356 diff lines)

### (A) `initialiseConfInit()` — unguarded `sccp.conf` parsing, runs on every request

Current code (line 278):
```php
public function initialiseConfInit(){
    $read_config = \FreePBX::LoadConfig()->getConfig('sccp.conf');
    $sccp_conf_init['general'] = $read_config['general'];
    foreach ($read_config as $key => $value) {
        if (isset($read_config[$key]['type'])) { // copy soft key
            if ($read_config[$key]['type'] == 'softkeyset') {
                $sccp_conf_init[$key] = $read_config[$key];
            }
        }
    }
    return $sccp_conf_init;
}
```

Two unguarded reads: `$read_config['general']` (direct read — undefined-key
fatal if `sccp.conf` has no `[general]` section, or the file doesn't exist
yet and `getConfig()` returns an empty/false result), and `foreach
($read_config as ...)` (fatal if `$read_config` isn't an array/Traversable —
`getConfig()` can plausibly return `false` for a missing/unreadable file).

**Reachability — confirmed high**: `initialiseConfInit()` is called from
`initializeSccpPath()` (`sccpManTraits/helperFunctions.php` calls it;
`Sccp_manager.class.php:699` `initializeSccpPath()` calls
`$this->initialiseConfInit()`), and `initializeSccpPath()` is called directly
from the `Sccp_manager` **constructor**
(`Sccp_manager.class.php:142: $this->initializeSccpPath();`). This means it
runs on literally every instantiation of the main module class — every page
load, every AJAX call. This is exactly the same "FreePBX instantiates
`Sccp_manager` in its constructor before install.php's own population logic
runs" window CLAUDE.md already documents for DB settings, just applied to
`sccp.conf` parsing instead. Also called a second time explicitly from
`install.php:1305`.

**Proposed fix**:
```php
public function initialiseConfInit(){
    $read_config = \FreePBX::LoadConfig()->getConfig('sccp.conf');
    $sccp_conf_init = array();
    $sccp_conf_init['general'] = isset($read_config['general']) ? $read_config['general'] : array();
    foreach (is_array($read_config) ? $read_config : array() as $key => $value) {
        if (isset($read_config[$key]['type'])) {
            if ($read_config[$key]['type'] == 'softkeyset') {
                $sccp_conf_init[$key] = $read_config[$key];
            }
        }
    }
    return $sccp_conf_init;
}
```

### (A) `getFileListFromProvisioner()` — no content validation before overwriting a working file

Current code (line 378):
```php
public function getFileListFromProvisioner(string $tftpRootPath) {
    // Use our own fork via raw.githubusercontent.com directly (not github.com/.../raw/,
    // which redirects through github.com - blocked in /etc/hosts to stop the module
    // update-check hang; see PHP8-MIGRATION-NOTES.md). Keeps us independent of upstream.
    $provisionerUrl = "https://raw.githubusercontent.com/nortien/provision_sccp/master/";
    // Get master tftpboot directory structure
    try {
        file_put_contents("{$tftpRootPath}/masterFilesStructure.xml",file_get_contents("{$provisionerUrl}tools/tftpbootFiles.xml"));
    } catch (\Exception $e) {
        return false;
    }
    return true;
}
```

Whatever `file_get_contents()` returns gets written straight to
`masterFilesStructure.xml`, **unconditionally overwriting whatever was there
before**, with no check that the response is actually valid XML. This is
called from 4 places, including live GUI/AJAX paths, not just install:
`views/advserver.model.php:158`, `install.php:1142`,
`Sccp_manager.class.php:629`, `sccpManTraits/ajaxHelper.php:566`.

If `raw.githubusercontent.com` ever serves a 404/rate-limit page, a captive
DNS/proxy intercept page, or any non-empty non-XML body — none of which raise
a PHP warning, since `file_get_contents()` "succeeded" — the *previously
good* `masterFilesStructure.xml` gets silently replaced with garbage. See the
`views/advserver.model.php` finding below for the concrete crash this causes
on the very next page load.

**Proposed fix** (validate before accepting; keep our own provisioner URL —
do **not** adopt timspb's `dkgroot/provision_sccp` source or their
cURL/fallback infrastructure, that's their own upstream choice, unrelated to
the actual bug):
```php
public function getFileListFromProvisioner(string $tftpRootPath) {
    $provisionerUrl = "https://raw.githubusercontent.com/nortien/provision_sccp/master/";
    $dest = "{$tftpRootPath}/masterFilesStructure.xml";
    try {
        $content = file_get_contents("{$provisionerUrl}tools/tftpbootFiles.xml");
    } catch (\Exception $e) {
        return false;
    }
    if (empty($content) || @simplexml_load_string($content) === false) {
        // Fetched content isn't valid XML - leave the existing file (if any) alone.
        return false;
    }
    return file_put_contents($dest, $content) !== false;
}
```

### (A) `views/advserver.model.php` — unguarded XML parse crashes the whole page

Current code (line 156-168):
```php
$selectArray = array();
//below probably unnecessary as installer should ensure that a copy always exists
// TODO: Maybe should always check here to ensure that have latest
if (!file_exists("{$this->sccppath['tftp_path']}/masterFilesStructure.xml")) {
    if (!$this->getFileListFromProvisioner($this->sccppath['tftp_path'])) {
        // File does not exist and cannot get from internet.
        return;
    };
}
$tftpBootXml = simplexml_load_file("{$this->sccppath['tftp_path']}/masterFilesStructure.xml");
$firmwareDir = $tftpBootXml->xpath("//Directory[@name='firmware']");

foreach ($firmwareDir[0] as $child) {
    if (!empty((string)$child['name'])) {
        $selectArray[(string)$child['name']] = (string)$child['name'];
    }
};
```

I verified both failure modes empirically against this box's actual PHP
8.2.33, with the box's strict-handler behavior:

```
--- simplexml_load_file() on an empty/invalid file, default handler ---
PHP Warning: simplexml_load_file(): ...: parser error : Document is empty
bool(false)
--- calling ->xpath() on that false, default handler ---
CAUGHT: Error: Call to a member function xpath() on bool
--- simplexml_load_file() on the same file, FreePBX-style strict handler ---
CAUGHT: ErrorException: simplexml_load_file(): ...: parser error : Document is empty
--- $firmwareDir[0] when xpath found nothing, strict handler ---
CAUGHT: ErrorException: Undefined array key 0
```

So **either** the file is invalid XML (immediate fatal `ErrorException` right
at `simplexml_load_file()` under the real FreePBX handler) **or** it's valid
XML but the query matches nothing (fatal on `$firmwareDir[0]`). Either way,
"Advanced Server → Model Information" (`views/advserver.model.php`, reached
via `advServerShowPage()`'s default branch) goes down for every admin until
someone manually fixes/deletes the file or reinstalls the module.

**Reachability**: `install.php`'s own `getMasterFileList()` guarantees a
*bundled fallback* (`contrib/masterFilesStructure.xml`) gets copied into the
TFTP root at install time if the provisioner fetch fails, so a fresh install
is not affected. The exposure is **after** install: any subsequent call to
`getFileListFromProvisioner()` (the "Update Files from Provisioner" button on
this same page, or the automatic re-check in `Sccp_manager.class.php:629`
whenever `masterFilesStructure.xml` goes missing) can silently corrupt the
file per the finding above, and the very next page load then fatal-crashes.

**Proposed fix** — suppress + validate, and fall back to the module's own
bundled copy rather than trusting a possibly-corrupt TFTP-root file (mirrors
what `getFileListFromProvisioner()` should already guarantee, belt-and-braces
since the file can also be touched outside this module, e.g. manually):
```php
$masterXmlPath = "{$this->sccppath['tftp_path']}/masterFilesStructure.xml";
if (!file_exists($masterXmlPath) || @simplexml_load_file($masterXmlPath) === false) {
    $this->getFileListFromProvisioner($this->sccppath['tftp_path']);
}
$tftpBootXml = @simplexml_load_file($masterXmlPath);
if ($tftpBootXml === false) {
    $bundled = dirname(__DIR__) . '/contrib/masterFilesStructure.xml';
    $tftpBootXml = is_readable($bundled) ? @simplexml_load_file($bundled) : false;
}
$firmwareDir = $tftpBootXml ? $tftpBootXml->xpath("//Directory[@name='firmware']") : array();
foreach (($firmwareDir[0] ?? array()) as $child) {
    if (!empty((string)$child['name'])) {
        $selectArray[(string)$child['name']] = (string)$child['name'];
    }
}
```
(Deliberately **not** adopting timspb's accompanying "Load Image" dropdown /
`firmwareOptionsByModel` / "Get Settings from Provisioner" / "Get Ringtones
from Provisioner" buttons in the same file — that's new UI functionality tied
to backend AJAX support we haven't verified we have, out of scope for a bug
fix. Just the crash-safety part above.)

### (A) `saveXml()` — silent failure on permission errors

Current code (line 370, in context — `saveXml()` is the shared helper used
throughout `xmlinterface.class.php` to write provisioning XML to the TFTP
tree):
```php
public function saveXml($xml, $filename) {
   ...
   $dom->preserveWhiteSpace = false;
   $dom->formatOutput = true;
   $dom->loadXML($xml->asXML());
   $dom->save($filename);
}
```

No check on `$dom->save()`'s return value, no check the target directory is
writable. `saveXml()` is called from `sccpManClasses/xmlinterface.class.php`
at 3 sites (lines 156, 393, 709) — this writes `XMLDefault.cnf.xml` (the main
provisioning file) and per-device `SEP*.cnf.xml` files. If the TFTP tree ends
up owned by the wrong user (this project's own CLAUDE.md explicitly documents
`sudo fwconsole chown` as a recurring "re-sync ownership after editing files
as a non-web user" step — i.e. this really happens on this project), `$dom->save()`
fails, and the module proceeds as if nothing happened: phones then get
stale/missing provisioning XML with **zero indication to the admin that
anything went wrong**.

**Proposed fix**:
```php
public function saveXml($xml, $filename) {
   ...
   $dom->preserveWhiteSpace = false;
   $dom->formatOutput = true;
   $dom->loadXML($xml->asXML());
   $dir = dirname($filename);
   if (!is_dir($dir)) {
       @mkdir($dir, 0755, true);
   }
   if (@$dom->save($filename) === false) {
       throw new \RuntimeException(sprintf(
           _('Failed to save XML to "%s". Check permissions (e.g. sudo fwconsole chown).'),
           $filename
       ));
   }
}
```

### (A) `getIpInformation()` — unguarded fields from `ip addr` output

Current code (line 75):
```php
private function getIpInformation($type = '') {
    $interfaces = array();
    switch ($type) {
        case 'ip4':
            exec("/sbin/ip -4 -o addr", $result, $ret);
            break;
        ...
    }
    foreach ($result as $line) {
        $vals = preg_split("/\s+/", $line);
        if ($vals[3] == "mtu") {
            continue;
        }
        ...
        $interfaces[$vals[1] . ':' . $vals[2]] = array('name' => $vals[1], 'type' => $vals[2], 'ip' => ...);
    }
    return $interfaces;
}
```

If any line from `ip -4 -o addr` doesn't split into at least 4
whitespace-separated fields (stray/blank line, unusual interface line format
across distros), `$vals[3]` is an unguarded read → fatal under the strict
handler. This feeds `createDefaultSccpXml()`'s `server_if_list`
(`Sccp_manager.class.php`), which runs on regular provisioning-refresh paths,
not just install.

**Proposed fix**: guard with `if (!isset($vals[1], $vals[2], $vals[3])) { continue; }` before use, matching timspb.

### (B)/(C) — not adopting
- Trait declared as `trait helperfunctions {` (line 5, lowercase 'f') vs
  timspb's `trait helperFunctions {` (uppercase, matching the filename and
  the `use \...\helperFunctions;` statement in `Sccp_manager.class.php:107`).
  PHP resolves trait/class names case-insensitively, so this is **not** a
  functional bug (confirmed: the module works today) — purely a naming
  consistency nit. Not worth a dedicated change, though harmless to fix in
  passing if `helperFunctions.php` is touched for the items above anyway.
- `checkTftpMapping()`'s `?? '/tftpboot'` vs our `?? ''` — both already
  guarded, just a different fallback value; not a bug either way.
- The `compareArrays()` / `sccpActiveDeviceKeyUsed()` /
  `sccpFindActiveDeviceByName()` functions timspb's diff removes are **our
  own** additions (case-insensitive AMI device-name matching, and the
  `array_udiff_assoc` comparator for the since-changed `saveSccpSettings()`
  diffing logic) that timspb's older/simpler fork snapshot doesn't have —
  we're ahead here, nothing to port.
- `before()`'s new behavior (return the *whole string* instead of `''` when
  the delimiter isn't found) is a genuine, if low-severity, behavior fix —
  our current `substr($inthat, 0, strpos($inthat, $thing))` returns `''` when
  `$thing` isn't in `$inthat}` (since `strpos()` returning `false` coerces to
  `0` as the length arg). Only call site is `Sccp_manager.class.php:1069`
  (`$this->before('@', $value)` on AMI hint names, which are almost always
  `exten@context` — malformed/legacy hints without `@` are the only exposure).
  Cosmetic-adjacent; folding into the general defensive-guard sweep rather
  than treating as a standalone item, but worth including if that file is
  touched: `return ($pos = strpos($inthat, $thing)) !== false ? substr($inthat, 0, $pos) : $inthat;` after a `null`/`''` guard.
- `createDefaultSccpConfig()`'s `$value['seq'] ?? null` / `$value['data'] ?? ''`
  guards: lower-confidence than the others in this file. `$sccpvalues` at
  every actual call site (`install.php:1307`, and all 3 in `ajaxHelper.php`)
  is sourced directly from `get_db_SccpSetting()`'s `SELECT keyword,
  sccpsettings.*`, so every row already carries all 5 columns (possibly
  `NULL` values, but not *missing keys*) — I could not construct a path where
  an entry in `$sccpvalues` is missing its own `'seq'`/`'data'` sub-key
  entirely. Cheap to add as insurance, not flagging as a proven bug.

---

## `views/server.info.php` (240 diff lines)

This is the "General SCCP Settings" diagnostic/info page — the one page an
admin is most likely to open *specifically because* something else is
already broken (AMI not connecting, chan-sccp not loaded, driver info
incomplete). That's exactly when the several unguarded reads below are most
likely to actually be empty/missing, so this page has a nasty habit of being
unavailable exactly when it's needed most.

### (A) `$tftpInfo[1]` used outside its own guard

Current code:
```php
exec('in.tftpd -V', $tftpInfo);
$info['TFTP Server'] = array('Version' => 'Not Found', 'about' => 'Mapping not available');

if (isset($tftpInfo[0])) {
    $tftpInfo = explode(',',$tftpInfo[0]);
    $info['TFTP Server'] = array('Version' => $tftpInfo[0], 'about' => 'Mapping not available');
    $tftpInfo[1] = trim($tftpInfo[1]);
    if ($tftpInfo[1] == 'with remap') {
        $info['TFTP Server'] = array('Version' => $tftpInfo[0], 'about' => $tftpInfo[1]);
    }
}
...
default:
    if ($tftpInfo[1] == 'with remap') {     // <-- OUTSIDE the isset($tftpInfo[0]) block above
```

If `in.tftpd` isn't installed/found on `$PATH` — plausible on any box not
running `tftpd-hpa`, e.g. one provisioning phones through a different/
external TFTP server — `exec()` leaves `$tftpInfo` as an empty array (`exec()`
always populates its output-array argument, even on failure, just with
nothing in it). `isset($tftpInfo[0])` is then false, so the reassignment
inside the `if` never happens — but the later `default:` branch (reached
whenever `tftp_rewrite` is anything other than `custom`/`pro`) still reads
`$tftpInfo[1]` on the now-still-empty array. Direct unguarded read → fatal
under the strict handler.

**Proposed fix**:
```php
exec('in.tftpd -V', $tftpInfo);
$tftpParts = array();
$info['TFTP Server'] = array('Version' => 'Not Found', 'about' => 'Mapping not available');
if (isset($tftpInfo[0])) {
    $tftpParts = explode(',', $tftpInfo[0]);
    $info['TFTP Server'] = array('Version' => $tftpParts[0] ?? '', 'about' => 'Mapping not available');
    if (isset($tftpParts[1])) {
        $tftpParts[1] = trim($tftpParts[1]);
        if ($tftpParts[1] === 'with remap') {
            $info['TFTP Server'] = array('Version' => $tftpParts[0] ?? '', 'about' => $tftpParts[1]);
        }
    }
}
...
default:
    if (isset($tftpParts[1]) && $tftpParts[1] === 'with remap') {
```

### (A) `$core[...]` cluster and `$driver['sccp']` — unguarded AMI/driver info

Current code:
```php
$info['sccp_class'] = $driver['sccp'];
$info['Core_sccp'] = array('Version' => $core['Version'],
                            'about' => "Sccp ver: {$core['Version']}   r{$core['vCode']}   Revision: {$core['RevisionNum']}   Hash: {$core['RevisionHash']}");
...
$info['chan-sccp build info'] = array('Version' => $core['Version'], 'about' => 'Following options NOT built:  ' . implode('; ',array_diff($capabilityArray, $core['buildInfo'])));
```

`$driver = $this->FreePBX->Core->getAllDriversInfo();` and `$core =
$this->aminterface->getSCCPVersion();` are both queries that can plausibly
come back incomplete precisely when chan-sccp itself has a problem — the
primary reason someone is on this page. Five unguarded array-key reads in
this small block; any one being absent is fatal.

**Proposed fix**: `$driver['sccp'] ?? ''`, and pull `$core[...]` into local
`?? ''`-guarded variables before building the strings/arrays (matches
timspb's approach).

### (A, lower severity) `$mysql_info['Value']`

```php
// $mysql_info
if ($mysql_info['Value'] <= '2000') {
```
`$mysql_info = $this->dbinterface->get_db_sysvalues();` (`SHOW VARIABLES LIKE
'%group_concat%'` via `PDO::fetch()`) returns `false` if no row matches.
`group_concat_max_len` is a standard MySQL/MariaDB system variable so this is
a narrow edge case (non-standard DB engine, or the query failing for some
other permission reason) — including for completeness, not a headline item.

**Proposed fix**: `if (!empty($mysql_info) && isset($mysql_info['Value']) && $mysql_info['Value'] <= '2000') {`

### (A, lower severity) `$cisco_tz['offset']`
```php
$cisco_tz = $this->extconfigs->getExtConfig('sccp_timezone', $conf_tz);
if ($cisco_tz['offset'] == 0) {
```
Unguarded; fatal if `getExtConfig()` doesn't recognize `$conf_tz` and returns
an array without `'offset'`. **Proposed fix**: `if (isset($cisco_tz['offset']) && $cisco_tz['offset'] == 0) {`

### (A, cosmetic-adjacent but worth doing together) missing output escaping
Nearly every value displayed on this page is echoed unescaped: AMI-derived
`$value['message']`/`$value['realm']` in the RealTime section, `$key`/`$value['Version']`/`$value['about']`
in the final info table, `print_r($this->class_error)` raw. See the
consolidated escaping finding below — this file is the single largest
concentration of the gap.

### (B)/(C)
- The `'<div class="alert ...">'` → plain-text `about` values (timspb moves
  the alert-styling out of the data and into markup, plus adds `_()`
  wrapping for a few labels) is a reasonable cleanup but purely cosmetic —
  not adopting as a bug fix, though harmless if picked up incidentally.
- `version_compare(phpversion(), '7.0.0', '>')` → `'8.2.0'` and message text
  update: cosmetic/informational only, not a bug (just an outdated version
  string in the "recommended" message on our current code — currently
  displays "OK" either way since we're on 8.2).

---

## `sccpManClasses/extconfigs.class.php` (59 diff lines)

### (A, lower confidence) defensive `?? ''` / `?? 'off'` reads in `updateTftpStructure()`

Current code (line 240):
```php
public function updateTftpStructure($settingsFromDb) {
    global $amp_conf;
    $adv_config = array('tftproot' => $settingsFromDb['tftp_path']['data'],
                      ...
    if (empty($settingsFromDb['tftp_rewrite_path']['data'])) {
        $settingsFromDb['tftp_rewrite_path']['data'] = $settingsFromDb['tftp_path']['data'];
    } else {
        if (!strpos($settingsFromDb['tftp_rewrite_path']["data"],$settingsFromDb['tftp_path']['data'])) {
            $settingsFromDb['tftp_rewrite_path']['data'] = $settingsFromDb['tftp_path']['data'];
        }
    }
    ...
    switch ($settingsFromDb['tftp_rewrite']['data']) {
```

I traced this one carefully since it looked like the same "sparse settings"
pattern as everything else, and it's worth showing the trace **because it's
a case where the diff looked scary but isn't currently exploitable** — this
is the "don't trust a diff line by itself" check the task asked for.

`updateTftpStructure()` is called from two places:
`install.php:1120` (`checkTftpServer()`) and `sccpManTraits/ajaxHelper.php:502`
(general-settings AJAX save). For the install path: `install.php` calls
`cleanUpSccpSettings()` (line 61) **before** `checkTftpServer()` (line 70).
`cleanUpSccpSettings()` loads `conf/sccpgeneral.xml.v{$sccp_compatible}` via
`initVarfromXml()` and merges every setting name declared there — including
`tftp_path`, `tftp_rewrite_path`, and `tftp_rewrite` (all three are declared
around line 1181-1198 of that XML) — into `$settingsFromDb` with full
`keyword`/`seq`/`type`/`systemdefault` structure (data possibly blank) before
`checkTftpServer()` ever runs. I confirmed this by grepping the whole repo:
these three keys are never assigned anywhere else with a full row shape, and
the live production DB (`sccpsettings` table, checked directly) has clean,
correctly-populated rows for all three. For the AJAX path,
`$this->sccpvalues` is loaded via `get_db_SccpSetting()` — a straight DB
read — so post-install it should always carry all 3.

I could **not** construct a currently-reachable path where these specific
keys are actually absent when `updateTftpStructure()` runs. That doesn't mean
it's impossible (e.g. some future XML-schema key rename, or a botched
partial install that skips `cleanUpSccpSettings()`), just that I can't prove
it today, unlike the other items in this report. Given how cheap the guards
are and how consistent this is with our own established defensive-read
convention elsewhere, I'd still adopt them, but as insurance rather than a
confirmed fix — flagging the confidence difference explicitly per the task's
instructions.

**Proposed fix** (adopting timspb's guards as-is):
```php
$adv_config = array('tftproot' => $settingsFromDb['tftp_path']['data'] ?? '/tftpboot', ...);
...
if (empty($settingsFromDb['tftp_rewrite_path']['data'] ?? '')) {
    $settingsFromDb['tftp_rewrite_path']['data'] = $settingsFromDb['tftp_path']['data'] ?? '/tftpboot';
} else {
    if (!strpos($settingsFromDb['tftp_rewrite_path']["data"] ?? '', $settingsFromDb['tftp_path']['data'] ?? '')) {
        $settingsFromDb['tftp_rewrite_path']['data'] = $settingsFromDb['tftp_path']['data'] ?? '/tftpboot';
    }
}
...
switch ($settingsFromDb['tftp_rewrite']['data'] ?? 'off') {
```

### (B) `#[\AllowDynamicProperties]` → explicit property declarations
timspb drops the attribute and instead explicitly declares `public
$paren_class = null;` / `public $sccpvalues = array();`. We already suppress
the PHP 8.2 dynamic-property deprecation via the attribute (broader coverage
— any property, not just these two). Functionally equivalent for the actual
issue; not adopting, no benefit.

### (C) — not adopting
- Dropped `ru_RU` locale entry: this is timspb's fork **missing** something
  we have, not the other way around. Nothing to port; note only.

---

## `Sccp_manager.class.php` (845 diff lines)

This file's diff is almost entirely the same "add `?? ''`/`isset()` guards
throughout" sweep already established and verified above, applied
pervasively across `getPhoneButtons()`, `createDefaultSccpXml()`,
`createSccpDeviceXML()`, `getSccpModelInformation()`, `getHintInformation()`,
etc. I'm not re-deriving reachability for every single one of the ~60
individual `?? ''` additions — the pattern and its justification (CLAUDE.md's
documented sparse-settings-plus-strict-handler issue) is the same throughout,
and it's now confirmed at least 6 separate ways elsewhere in this review.
Recommend a general sweep of this file using the same pattern. Below are the
handful of items that are **not** just that pattern and deserve individual
treatment.

### (A) Constructor try/catch — observability, not just crash-avoidance

Current code (line 111):
```php
public function __construct($freepbx = null) {
    if ($freepbx == null) {
        throw new Exception("Not given a FreePBX Object");
    }
    $this->class_error = array();
    $this->FreePBX = $freepbx;
    ...
    $this->sccpvalues = $this->dbinterface->get_db_SccpSetting();
    $this->dbsccpvalues = $this->sccpvalues;
    $this->initializeSccpPath();
    $this->updateTimeZone();
    $this->saveSccpSettings();
}
```
Given how many "fatal under sparse data" paths this review turned up across
the codebase, an uncaught exception here currently means a bare fatal with
whatever FreePBX's default error page shows — not actionable for an admin.
timspb wraps the whole body in try/catch, records the exception into
`$this->class_error` (which `views/server.info.php` already displays, in the
"Diagnostic information about SCCP Manager errors" block), logs it via
FreePBX's logger where available, and **re-throws** — so behavior for
existing callers is unchanged, this purely adds a diagnostic trail.

**Proposed fix** (adapted to keep our current constructor body, including
the `$this->dbsccpvalues` line which timspb's fork removed — see the
`saveSccpSettings()` warning below, do **not** drop that assignment):
```php
public function __construct($freepbx = null) {
    try {
        if ($freepbx == null) {
            throw new \Exception("Not given a FreePBX Object");
        }
        $this->class_error = array();
        $this->FreePBX = $freepbx;
        $this->db = $freepbx->Database;
        $this->cnf_wr = \FreePBX::WriteConfig();
        $this->cnf_read = \FreePBX::LoadConfig();
        // ... existing driver-loading loop unchanged ...
        $this->sccpvalues = $this->dbinterface->get_db_SccpSetting();
        $this->dbsccpvalues = $this->sccpvalues;
        $this->initializeSccpPath();
        $this->updateTimeZone();
        $this->saveSccpSettings();
    } catch (\Throwable $e) {
        $this->class_error = array('Sccp_manager load' => $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        error_log('Sccp_manager: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        throw $e;
    }
}
```

### (A) `initializeSccpPath()` — blank-string path fallbacks are worse than a crash

Current code (line 699):
```php
function initializeSccpPath() {
    $this->sccppath = array(
                'asterisk' => ($this->sccpvalues['asterisk_etc_path']['data'] ?? ''),
                'tftp_path' => ($this->sccpvalues['tftp_path']['data'] ?? ''),
                'tftp_templates_path' => ($this->sccpvalues['tftp_templates_path']['data'] ?? ''),
                'tftp_store_path' => ($this->sccpvalues['tftp_store_path']['data'] ?? ''),
                'tftp_lang_path' => ($this->sccpvalues['tftp_lang_path']['data'] ?? ''),
                'tftp_firmware_path' => ($this->sccpvalues['tftp_firmware_path']['data'] ?? ''),
                'tftp_dialplan_path' => ($this->sccpvalues['tftp_dialplan_path']['data'] ?? ''),
                'tftp_softkey_path' => ($this->sccpvalues['tftp_softkey_path']['data'] ?? ''),
                'tftp_countries_path' => ($this->sccpvalues['tftp_countries_path']['data'] ?? '')
              );
    ...
}
```
These reads are already guarded against the *undefined-key* fatal — but the
fallback is an **empty string**, and this array feeds path construction
throughout the codebase like `"{$this->sccppath['tftp_templates_path']}/somefile"`.
An empty prefix means that resolves to `/somefile` — the filesystem **root**.
This is a step worse than a crash: some of these paths get passed to
`mkdir()`/`fopen(..., 'w')` elsewhere (e.g. `extconfigs.class.php`'s
`$adv_ini = "{$settingsFromDb['tftp_rewrite_path']["data"]}/index.cnf";`,
similar shape), so a sparse-settings window doesn't just crash — depending on
process permissions it could silently attempt filesystem operations at `/`.
Since `initializeSccpPath()` runs on every construction (per the
`initialiseConfInit()` finding above) and CLAUDE.md documents settings being
sparse right after fresh install as a real, recurring window, this is worth
hardening to something that at least stays inside the expected TFTP tree.

**Proposed fix** (matches timspb's intent; adopting the "default to
`/tftpboot`-relative path" idea, not necessarily their exact helper-closure
styling):
```php
function initializeSccpPath() {
    $tftpBase = $this->sccpvalues['tftp_path']['data'] ?? '';
    $tftpBase = ($tftpBase !== '') ? rtrim($tftpBase, '/') : '/tftpboot';
    $pathOrDefault = function ($key, $suffix) use ($tftpBase) {
        $v = $this->sccpvalues[$key]['data'] ?? '';
        return ($v !== '') ? $v : ($tftpBase . $suffix);
    };
    $this->sccppath = array(
                'asterisk' => $this->sccpvalues['asterisk_etc_path']['data'] ?? '/etc/asterisk',
                'tftp_path' => $tftpBase,
                'tftp_templates_path' => $pathOrDefault('tftp_templates_path', '/templates'),
                'tftp_store_path' => $pathOrDefault('tftp_store_path', '/settings'),
                'tftp_lang_path' => $pathOrDefault('tftp_lang_path', '/locales'),
                'tftp_firmware_path' => $pathOrDefault('tftp_firmware_path', '/firmware'),
                'tftp_dialplan_path' => $pathOrDefault('tftp_dialplan_path', '/dialplan'),
                'tftp_softkey_path' => $pathOrDefault('tftp_softkey_path', '/softkey'),
                'tftp_countries_path' => $pathOrDefault('tftp_countries_path', '/locales/countries')
              );
    ...
}
```

### **DO NOT PORT** — `saveSccpSettings()` reverting to truncate+reinsert-on-every-load

Current code (line 819):
```php
private function saveSccpSettings($save_value = array()) {
    if (empty($save_value)) {
        $diffToSave = array_udiff_assoc($this->sccpvalues, $this->dbsccpvalues, array($this, "compareArrays"));
        $this->dbinterface->write('sccpsettings', $diffToSave, 'update');
    } else {
        $this->dbinterface->write('sccpsettings', $save_value, 'update');
    }
    return true;
}
```
timspb's version:
```php
private function saveSccpSettings($save_value = array()) {
    if (empty($save_value)) {
        $this->dbinterface->write('sccpsettings', $this->sccpvalues, 'replace'); //Change to replace as clearer
    } else {
        $this->dbinterface->write('sccpsettings', $save_value, 'update');
    }
    return true;
}
```
(with the matching `private $dbsccpvalues = array();` property, and the
`$this->dbsccpvalues = $this->sccpvalues;` constructor line, **both removed**
in timspb's diff.)

Per `dbinterface.class.php::write()`, `'replace'` mode for the `sccpsettings`
table does `TRUNCATE sccpsettings` followed by a bulk `INSERT` of every
setting — i.e. every single row, rewritten, on every page load (since
`saveSccpSettings()` with no argument is called unconditionally at the end of
the constructor, which per the findings above runs on every request). This
is **exactly** the bug our own commit `96acb02` ("Only write changed settings
to DB instead of truncate+reinsert on every load") already fixed — the diff
logic (`array_udiff_assoc($this->sccpvalues, $this->dbsccpvalues, ...)`) that
timspb's diff removes *is* that fix. Porting any part of this change —
including, critically, dropping the now-apparently-unused-looking
`$this->dbsccpvalues` property/assignment, which is easy to do by accident
while cleaning up nearby code — would silently reintroduce a real,
already-diagnosed-and-fixed performance/write-amplification bug. Flagging
prominently since this is the single easiest regression to accidentally
reintroduce while adopting the other, legitimate `?? ''` guards scattered
through the rest of this same diff (the surrounding lines look identical in
shape to genuinely good changes).

### (C) — not adopting
- `use \FreePBX\modules\Sccp_Manager\...` (capital `M`) vs timspb's
  `Sccp_manager` (lowercase, matching the trait files' own namespace
  declaration and the class's own `Sccp_manager` name) — PHP resolves
  namespaces case-insensitively; confirmed the module works today as-is.
  Cosmetic-only naming inconsistency, not a bug.
- `#[\AllowDynamicProperties]` → `#[AllowDynamicProperties]` (dropped leading
  backslash): tested empirically — PHP resolves this unqualified attribute
  name to the global-namespace built-in either way (attribute resolution
  isn't subject to the same "no fallback to global namespace" rule I
  initially assumed for plain class references). No functional difference;
  we already have the (correctly-qualified) attribute either way.
- `processPageData()`'s removal of the `$device_warning` → `$page['banner']`
  wiring, and the dropped `"banner" => _(...)` entries in
  `settingsShowPage()`: timspb's fork doesn't have our page-level shared
  warning-banner mechanism (see `page.html.php`/`server.device.php`/
  `server.setting.php`/`form.adddevice.php` below) — this is our own,
  cleaner architecture that timspb doesn't have, not something missing on
  our side.
- `getSccpModelInformation()`'s large firmware-file-detection rewrite
  (multiple `.loads`-extension-variant / per-model-subdirectory / tftp-root
  fallback search paths) plausibly fixes real spurious "firmware not found"
  warnings, but it's a substantial, TFTP-layout-dependent rewrite that's hard
  to verify safe without knowing our exact on-disk firmware directory
  conventions match its assumptions, and a wrong port here risks the
  opposite failure mode (masking a genuinely missing firmware file).
  Flagging as worth a closer, dedicated look later rather than porting
  blind as part of this bug-fix-focused review.

---

## Missing output escaping (defense-in-depth XSS, several files)

Grouping these together since they're the same underlying gap, found in
several files, rather than repeating the same reasoning per-file.

**Existing precedent**: `sccpManClasses/formcreate.class.php`'s main
input-value rendering already does this correctly —
`htmlspecialchars($curData, ENT_QUOTES)` (line 119) and
`htmlspecialchars($sysDefault, ENT_QUOTES)` (lines 122, 377, 379) — and the
already-fixed `ajaxHelper.php` (previous review round) also escapes. The gaps
below are places that echo admin-configurable settings data or AMI-derived
strings into HTML **without** going through that same treatment — not a
crash, but a real stored-XSS gap in FreePBX's multi-admin-ACL model (a
FreePBX install can have multiple admin accounts with different per-module
permissions; one admin's ability to *set* a value doesn't guarantee they're
the only admin who ever *views* it back).

- `views/server.codec.php:38` — `<?php echo $sccp_disallow_def ?>` (the
  `sccp_disallow` codec-list field value, echoed straight into a `value="..."`
  attribute).
- `views/form.adddevice.php` — hidden field values `sccp_device_id`
  (`'<input type="hidden" name="sccp_device_id" value="'.$dev_id.'">'`) and
  the commented-out `sccp_deviceid` field; also the device-firmware/template
  warning block if that gets re-added.
- `views/server.info.php` — see the dedicated section above; the largest
  concentration (RealTime connector messages, `$core`/`$driver` info,
  `class_error` dump, the whole `$info` results table).
- `sccpManClasses/formcreate.class.php` — `<option>` value/label text in
  `addElementSD()`/`addElementSL()`/`addElementSLNA()` (device-model,
  extension, dialplan-template, language/country dropdown entries sourced
  from DB data) is **not** escaped, unlike the main input-value path in the
  same file.

**Proposed fix**: add a small helper (matching timspb's, since it's exactly
the right shape) to `Sccp_manager.class.php`:
```php
/**
 * Escape string for safe HTML output (XSS prevention).
 * @param string $s
 * @return string
 */
public function escapeHtml($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```
and use it (or plain `htmlspecialchars($x, ENT_QUOTES)`, matching the
existing `formcreate.class.php` convention, whichever fits better contextually)
at the specific gaps listed above. Not proposing a wholesale sweep of every
view file — most of the module's actual input rendering already goes through
`formcreate.class.php`'s properly-escaped `showGroup()` path; these are the
manually-coded spots that bypass it.

---

## `sccpManClasses/formcreate.class.php` (1295 diff lines) — do not port

This is the single largest diff in the whole review, and none of it should
be ported. timspb's version is a wholesale reversion of the form-rendering
rework this project's own CLAUDE.md documents as an intentional, already-completed fix:

> The form-rendering layer (`sccpManClasses/formcreate.class.php`) was
> reworked to match FreePBX's native Bootstrap 4 layout conventions, fixing
> real bugs the old layout masked: a customize/restore toggle that silently
> froze chan-sccp defaults into the DB on any save, firmware downloads
> landing in the wrong TFTP path depending on `tftp_rewrite` mode, and icons
> rendering as nothing under FreePBX 17's theme.

timspb's `addElementIE()`/`addElementIS()` (and others) reintroduce exactly
that toggle: a hidden checkbox (`sccp-edit`/`sccp-restore` classes,
`data-default="..."` attributes) that switches between "using system
defaults" and "customised", plus a separate hidden `edit_<res_id>` row with
its own copy of the input, and a "Use %s defaults" button — the precise
pattern our own documentation identifies as the thing that silently froze
chan-sccp defaults into the DB on save. It also uses Bootstrap-3-era
`glyphicon glyphicon-check`/`glyphicon-uncheck` icon classes
(`addElementIED()`, line ~415 of the diff) rather than Font Awesome, which
lines up with the "icons rendering as nothing under FreePBX 17's theme" bug
our rework separately fixed — FreePBX 17 ships Bootstrap 4 + Font Awesome,
not Bootstrap 3 glyphicons, so this would very plausibly render as invisible
icons on our actual GUI. Both of these line up too precisely with
already-diagnosed, already-fixed bugs to be coincidence; timspb's fork
appears to be maintained against an older FreePBX/Bootstrap-3-era target, not
FreePBX 17.

Two smaller ideas embedded in the same diff, called out separately since
they're orthogonal to the toggle-UI regression above:

- **`safeStr()` helper** (converts SimpleXML/array values to string safely,
  avoiding "Array to string conversion" notices) — this is the same failure
  class as our own already-fixed
  `10f5128 Fix fatal "Array to string conversion" crash in installDbPopulateSccpline()`,
  so the general idea is sound. But our current `formcreate.class.php`
  already casts every SimpleXML property access explicitly
  (`(string)$child->input[0]->name` etc.) as part of the documented rework,
  and I don't have evidence any of those casts is currently hitting an actual
  array-valued SimpleXML node in practice. Not proposing this as a concrete
  fix without a reproduced crash first — noting it here as a pattern to
  reach for *if* an "Array to string conversion" notice is ever observed
  from this file, not as a standing action item.
- **`<option>` value escaping** — real, already captured in the consolidated
  escaping section above.

**Recommendation**: do not port any structural change from this file. If
`addElementSD()`/`addElementSL()`/`addElementSLNA()` need escaping fixes,
apply just that (the specific `<option>` lines), left in place inside our
existing (non-toggle) rendering structure.

---

## Fork-specific UI features — not adopting (not bugs, out of scope)

These are genuine new functionality in timspb's fork, not correctness fixes,
so out of scope for this bug-focused review even where they look nice:

- `views/hardware.phone.php` / `views/hardware.extension.php` — green/red row
  status color-coding (`sccpDeviceRowStyle`/`sccpExtensionRowStyle`,
  `StatusColorFormatter`/`LineStatusColorFormatter`) for device/extension
  registration status. New UX, not a fix.
- `views/advserver.model.php` — the "Load Image" dropdown replacing a
  free-text input, and "Get Settings from Provisioner"/"Get Ringtones from
  Provisioner" buttons — new functionality tied to backend AJAX support not
  verified to exist on our side.
- `views/hardware.phone.php`'s `LineFormatter()` JS: `result = '';` (no
  `var`/`let`, an accidental implicit global in non-strict JS) vs timspb's
  `var result = '';`, plus a friendlier "No line" placeholder instead of
  `-- EMPTY --`. Real but very minor (single-page scope, no observed
  collision); not worth a dedicated fix, mentioning for completeness only.

---

## Files with zero differences (no action)
`page.sccp_adv.php`, `page.sccp_phone.php`, `page.sccpsettings.php`,
`views/advserver.dialtemplate.php`, `views/form.addruser.php`,
`views/form.devadvanced.php`, `views/formShowError.php`,
`views/formShowSysDefs.php`, `views/hardware.rnav.php`,
`views/server.advanced.php`, `views/server.datetime.php`,
`views/server.url.php`.

## Small (B)/(C) items in remaining files, not written up individually
- `sccpManTraits/bmoFunctions.php` — adds `getCSS()`/`getJS()` methods and a
  CSRF-context comment. Checked: this FreePBX version's asset-loading
  (`admin/libraries/view.functions.php::framework_include_css/js()`) works
  by **scanning `assets/css`/`assets/js` directories directly** — there is no
  `getCSS()`/`getJS()` BMO hook anywhere in this FreePBX installation (grepped
  the whole `admin/` tree). Adding these methods would be dead code that's
  never called. Not adopting. CSRF comment is a no-op comment, harmless either
  way, not adopting.
- `sccpManClasses/amInterfaceClasses/Message.class.php` — `#[\AllowDynamicProperties]`
  removals (same as elsewhere, we already cover this via the attribute,
  no action); `sanitizeInput()` param rename (cosmetic); the new
  non-ASCII-stripping branch is a trade-off, not a clean win (see Response.class.php
  section above for the shared reasoning — this file has the same change).
- `views/page.html.php` — timspb's escaping additions for `$display_info`/
  tab `$key`/`$page['name']` are unnecessary: I traced all of these back to
  `Sccp_manager.class.php`'s `settingsShowPage()`/`phoneShowPage()`/
  `advServerShowPage()`/`infoServerShowPage()` and confirmed every value is a
  hardcoded string literal (`"general"`, `_("Site Default Settings")`, etc.),
  never user input. Not a real gap. Also: timspb's version **removes** our
  own custom banner-sync `<script>` block (the mechanism behind the
  `"banner" => _(...)` entries throughout `Sccp_manager.class.php`) — that's
  their fork simply not having a feature we built, not something to adopt.
  Modal title/button i18n string wrapping (`_('Close')` etc.) is cosmetic.
- `views/advserver.keyset.php`, `views/server.setting.php`,
  `views/server.device.php` — same banner-architecture divergence as
  `page.html.php` (they inline the warning banner in each view; we render it
  once, shared, via `page.html.php`), plus cosmetic i18n string wrapping.
  Not adopting.
- `views/hardware.sphone.php`, `views/hardware.phone.php` — `DispayTypeFormatter`
  → `DisplayTypeFormatter` typo fix. Purely cosmetic: the typo is used
  consistently on both the `data-formatter="..."` attribute and the JS
  function declaration in our current code, so it already works correctly;
  fixing the spelling doesn't change behavior.
- `views/form.dptemplate.php` — minor variable-declaration reordering (no
  behavior change) and removal of a "Dial Plan Help" documentation panel
  (content we still have, they dropped it — nothing to port).
- `views/hardware.extension.php` — same row-color-coding feature as
  `hardware.phone.php` above; new UX, not a fix.
