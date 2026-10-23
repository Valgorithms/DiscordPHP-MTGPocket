# Death observer subtype look-back

A dying transformed or face-down creature becomes its printed front face in the graveyard. Surviving subtype-qualified death observers previously inspected that new face, missing the actual battlefield subtype and potentially triggering for one it did not have.

The engine now retains the dying creature's immutable `CardDefinition` before its move and supplies it only to the subtype filter for other-creature death observers. Other trigger callers keep their existing behavior. The pre-move face follows the look-back requirement in rules 603.10/603.10a of the [official Comprehensive Rules](https://media.wizards.com/2026/downloads/MagicCompRules%2020260619.pdf).

`DeathSubtypeLookbackTest` uses synthetic Human/Elf face fixtures, paid public transform/morph/removal actions, and actual JSON save/resume. Front-face Human is a positive control; the transformed Elf must trigger only Elf observers, and a face-down creature with no subtypes must trigger neither. The graveyard record is still its printed Human face. Saved pending removal replays deterministically without duplicate effects.

Before the fix, the transformed and face-down cases failed while the front-face control passed (3 tests / 32 assertions / two failures). After the fix, all three pass (38 assertions); together with animated-death regression coverage, 15 tests / 230 assertions pass under a 30-second process-tree cap. Pint on both changed PHP files passes. These are offline tests with injected card data; they do not load a bot or imported corpus.

This is a bounded static-definition look-back, not general last-known information: continuous subtype changes, all-creature-types effects, departing observers, source ability changes and simultaneous mass-removal semantics remain separate. Parser recognition and saved-state shape are unchanged. Whole-card functional coverage is still unmeasured. `env.example` needs no new variables. No live bot, import, build, restart or deployment is included.
