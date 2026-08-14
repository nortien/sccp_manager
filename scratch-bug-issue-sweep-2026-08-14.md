# chan-sccp/sccp_manager `bug`-labeled issue sweep (2026-08-14, task #10)

## Method

`CLAUDE.md`'s "Open items" flags `github.com/chan-sccp/sccp_manager/issues`
(the upstream org's own tracker, distinct from `/pulls`, which *was* swept
2026-08-14 - see "chan-sccp, provision_sccp, and the sccp_manager PR sweep")
as **not yet triaged**. This is the first pass.

1. `GET /repos/chan-sccp/sccp_manager/issues?labels=bug&state=open&per_page=100`
   - **1 result**, no PRs mixed in. `chan-sccp/sccp_manager`'s upstream issue
     tracker is far smaller than the driver's (24 bug-labeled issues there,
     see `/usr/src/chan-sccp/scratch-bug-issue-sweep-2026-08-14.md`) - this
     repo just doesn't have many issues filed against it at all, `bug`-labeled
     or otherwise (this org's traffic is overwhelmingly on the driver, not
     the GUI module).
2. Fetched the issue's full body + all 9 comments, read in full.
3. Cross-referenced against our actual current source at
   `/var/www/html/admin/modules/sccp_manager` (branch `stable`) - not just
   the issue text, per this project's standing "Upstream-issue caution" rule.

## #67: 7937 phones do not go into sleep mode

**Verdict: real, confirmed, fixed.**

Reported 2022-01-28: "The 7937 phone does not go into sleep mode and the
screen is lit for 24 hours." (Also carries `enhancement` alongside `bug` -
included anyway, it's a plain bug report with a config-schema fix, not a
feature request.)

The maintainer (`dkgroot`, upstream author) diagnosed it precisely without
ever seeing our fork's template - his comment lists the exact Cisco
`vendorConfig` XML elements that control phone display/backlight idle
behavior: `displayRefreshRate`, `daysDisplayNotActive`, `displayOnTime`,
`displayOnDuration`, `displayIdleTimeout`, `displayOnWhenIncomingCall`, plus
the `backlight*` equivalents. His suggestion: *"Maybe some of the display
related 'backlight' entries are missing from the cnf.xml template files...
We don't own each and every type of phone, so we cannot verify support on
all models."* Another commenter tried adding lines to their own template,
got no result at first ("it didn't work("), asked a clarifying question
about SIP vs SCCP vendorConfig (dkgroot: mostly the same, some differences
"only experimentation will be able to prove"), then reported "fixed, thanks"
three days later - **but never posted the exact XML they used**, and a
follow-up question asking them to share the solution went unanswered. So
the issue itself doesn't tell us the exact fix, just the family of fields
responsible and confirmation that adding *something* in that family worked
for at least one real 7937.

**Verified against our actual template**
(`conf/SEP0000000000.cnf.xml_7937_template`): the `<vendorConfig>` block had
**none** of the fields dkgroot named - not `displayIdleTimeout`, not
`daysDisplayNotActive`, nothing display/backlight-related at all. Just
`garp`, `voiceVlanAccess`, `sshAccess`, `lldpAssetId`, `voiceQualityControl`.
`<idleTimeout>0</idleTimeout>` exists at the top level but that's a
different Cisco concept entirely (controls the `idleURL` auto-launch timer,
not display/backlight power) - doesn't touch this bug.

**Cross-checked every sibling 79xx-series template in this repo** (`7975`,
`796x`, `797x`, `79df`) - **all four already carry**
`daysDisplayNotActive`/`displayOnTime`/`displayOnDuration`/`displayIdleTimeout`
with identical values (`1,7` / `08:30` / `11:30` / `01:00`) across every one
of them. This is a real, established, consistent convention already in this
codebase for every other phone in this hardware generation - **the 7937
template is the one outlier that never got it**, which is exactly why it's
the one model reported not going to sleep. Not a hypothesis - a directly
observable gap once you diff siblings against it.

**Fix applied** (`conf/SEP0000000000.cnf.xml_7937_template`): added the same
4 fields with the same values used by every sibling template, matching the
established convention rather than inventing new defaults:

```xml
<daysDisplayNotActive>1,7</daysDisplayNotActive>
<displayOnTime>08:30</displayOnTime>
<displayOnDuration>11:30</displayOnDuration>
<displayIdleTimeout>01:00</displayIdleTimeout>
```

Modeled specifically on the `796x`/`79df` templates (not `7975`/`797x`,
which additionally carry `displayOnWhenIncomingCall` and a longer list of
video/CDP/LLDP-related fields) - the 7937 is a non-video audio-conference
unit like `796x`/`79df`, not a `videoCapability`-bearing phone like
`7975`/`797x`, so the minimal 4-field set is the better-matched precedent,
not the larger one.

**Verification**: XML well-formedness checked via
`simplexml_load_file()` under `php -r` (this project's normal substitute for
`xmllint`, which isn't installed on this box) - parses cleanly, confirmed
`vendorConfig->displayOnTime` reads back `08:30` as written. File ownership
restored to `asterisk:asterisk` via `sudo fwconsole chown` after editing
(this repo's `conf/` tree is owned `asterisk:asterisk`, not the
`sangoma:asterisk` most of the rest of the module uses - had to `chown` to
`sangoma` to make the edit, then run the module's normal chown-restore step
immediately after, per this project's own documented "always fwconsole
chown after editing files as a non-web user" operational note).

**Live GUI/provisioning verification not done** (no real 7937 hardware on
this box to confirm the phone actually sleeps now) - same "owed, not
blocking" bar this project has used all day for PHP-adjacent fixes without
live hardware to test against. If a 7937 is ever provisioned against this
box, checking that the screen actually goes idle after ~1 hour outside the
08:30-20:00 window would close this out for real.

## No other bug-labeled issues found

That's the complete set - `chan-sccp/sccp_manager`'s open-issue `bug` label
currently has exactly one item, and it's now addressed. Unlike the driver
repo's sweep (21 new issues to triage), this side of task #10 was a much
smaller lift purely because there's much less filed against this repo
upstream in the first place.
