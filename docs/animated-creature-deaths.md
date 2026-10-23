# Animated creature death observers

`Game::moveTo()` already records whether a permanent is a creature immediately before it moves to the graveyard, after applying supported destination replacements. The subsequent printed-type check discarded that fact for animated lands and artifacts: animation is cleared on a zone change, so surviving death observers and existing recover handling never ran.

The death path now uses the already-captured `$dies` value for those notifications. The existing pre-move controller determines whose creature died; the card still enters its owner's graveyard. This follows the look-back requirement for battlefield-leaving triggers in rules 603.10/603.10a and the death definition in rule 700.4 of the [official Comprehensive Rules](https://media.wizards.com/2026/downloads/MagicCompRules%2020260619.pdf). No new parser contract or saved-state field was added.

`AnimatedCreatureDeathTest` exercises activated animation through the public engine API, then casts paid removal and resumes actual JSON-decoded saved state. It includes Moldervine Reclamation's death/life/draw text and explicitly named synthetic animation, opposing/global observer and recover fixtures. These are bounded mechanic tests, not whole-card certification.

Actual local evidence (PHP 8.5.12, process-local Xdebug off, 30-second process-tree caps):

- Before the fix: all five own/opposing/stolen animated-creature death cases failed to notify observers; the recover case also failed. Six non-death controls passed.
- After the fix: 12 tests / 192 assertions pass, including saved pending-removal deterministic replay and exactly-once effects. Both land and artifact animation are tested; the stolen object's controller differs from its owner.
- Negative controls: regeneration, indestructibility, direct exile, exile replacement, bounce and an ordinary unanimated artifact cause no creature-death notification.
- Pint passes for the runtime file and new regression class.

The parser is unchanged, so this adds no recognized card names and reuses the existing recognition measurements. Whole-card functional coverage remains unmeasured. General simultaneous death/leave observers, full last-known characteristics (including subtype/ability changes), APNAP choice ordering and recover's existing automatic payment policy remain separate limitations. No configuration contract changed; `env.example` requires no new variables. No live bot, import, build, restart or deployment was used.
