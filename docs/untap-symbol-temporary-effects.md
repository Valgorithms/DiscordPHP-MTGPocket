# Untap-symbol temporary effects

The existing untap-cost path now recognizes three additional exact cached Shadowmoor ability forms: Safehold Sentry's temporary +0/+2, Merrow Wavebreakers' temporary flying, and Merrow Grimeblotter's targeted temporary -2/-0. The shared cost path retains its stun replacement and summoning-sickness behavior; no generic untap-symbol parser is claimed.

At base `8602b2150f7e7660d866ba7d25cc81e8bdf0ce9b`, six paid-cast and activation cases across both seats reached 24 assertions and all errored because the abilities were unread. The correction passes 20 tests and 182 assertions, covering paid mana and untap costs, stun replacement, invalid-cost and target rejection without mutation, source and target departure, expiry and JSON restoration. Opposing creatures and response spells are test enablers, not additional card certificates.

All records come from the existing SHM pool SHA-256 `d2f7b7afdae8fecb211988e34236cac8b5afbdd3ae6407fb5863fa57bfbecfde`: Safehold Sentry UUID `28aa77b1-24ca-56bf-a3a1-28f674bb88e3`, Merrow Wavebreakers UUID `32d2a2e8-6a84-560e-bb57-356b8da657ac`, and Merrow Grimeblotter UUID `68205de9-ca1c-5709-a25d-be8baaaef24a`. Normalized fixture SHA-256 is `2a96c1bce44c80a21232eb294834eb0ec81110ed8275785e77455cc57a3d017c`. No corpus import or save schema change is involved; rollback restores old unread parsing for future loads.

Other untap-symbol forms, untap observers, Ward timing, source or target re-entry, general replacement ordering, newest printings and whole-card certification remain outside the evidence. Held PRs #444–#452 need later integration and revalidation against current main.
