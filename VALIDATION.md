# Validation

Validated on a physical Unraid server with Intel CPUFreq. Machine identifiers, exact hardware specifications, original CPU settings, and server configuration exports are intentionally omitted.

## Automated checks

- Reproducible plugin build, XML parsing, and byte-for-byte verification of all seven embedded files and update checksums.
- PHP, JavaScript, and installer shell syntax.
- **58 backend checks** covering Intel, AMD global boost, per-policy boost, missing controls, heterogeneous policies, shared bounds, input validation, saved settings, locking, failed writes, save failures, rollback, minimum-frequency preservation, changing turbo ranges, and delayed CPUFreq QoS readback.
- Browser checks with synthetic data cover the Turbo Boost checkbox, mixed and unavailable boost states, absent preset buttons, 100 MHz notches, refreshed bounds after governor and turbo changes, unusual hardware limits, unchanged-value no-ops, one save per slider release, write-error recovery, and expired sessions.

## Earlier live checks

These checks cover the CPU backend and previous UI. The current UI revision is validated locally with synthetic browser tests; it has not been installed or published.

- Installed using Unraid's actual Plugin Manager; initial installation preserved existing CPU settings.
- Verified the real dashboard tile in Chromium, including initial values and the conflict notice. No browser JavaScript errors occurred.
- Verified anonymous API access is rejected, POST without a CSRF token returns 403, and an invalid governor returns 422 without changing CPU settings.
- Applied different governors across every detected policy.
- Disabled turbo and verified the kernel reduced the reported maximum.
- Applied a lower frequency cap across every policy. Existing minimums remained unchanged.
- Verified governor changes leave turbo and frequency caps unchanged.
- Exercised Plugin Manager reinstall/update with saved settings, including payload checksum replacement and the same restore path used at boot. No reboot was performed.
- Restored and saved the original CPU settings across all detected policies, preserving every original minimum.
- Compared SHA-256 checksums before and after testing: Tips and Tweaks and Unraid's native `dynamix.cfg` remained unchanged. No array restart was performed.

The screenshot in the README uses synthetic CPU data, not a server capture. Test fixtures use synthetic frequency ranges and contain no machine identifiers. The plugin was left installed after testing.

Other hardware and driver configurations are covered by simulated filesystem tests only.
