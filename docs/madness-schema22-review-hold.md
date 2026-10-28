# Private Madness schema22 review hold

This local proposal is not approved for publication or rollout. The instruction to keep schema21 unchanged was not authorization to change the saved-game version. Its existing schema17/18 already-paid bare-target drain-or-reviewed-recovery hold remains in force.

The candidate's `toArray()` writes schema22 for **every game save**, including games that never involve Madness. An older schema21 reader rejects all such saves; rollback compatibility therefore affects the entire saved-game population, not only in-flight Madness choices. Any versioning or rollout decision needs separate review, including safe treatment of in-flight and already-upgraded saves. No live match storage was inspected.

An `optional_madness` choice restored by the candidate requires the original owner and source to exist in exile with the Madness marker and the exact saved incarnation. Stale, returned, missing, non-exile or differently marked sources are refused before `fromArray()` returns a usable game. Neither a newer object incarnation nor its zone is changed, and the choice is not auto-resolved. Existing live action and already-paid continuation checks remain independently enforced. This is bounded to the ordinary standalone trigger; it does not claim copied, modified or merged trigger coverage, whole-card certification, or a general schema migration policy.
