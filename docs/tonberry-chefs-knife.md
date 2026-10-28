# Tonberry Chef's Knife

The cached FIN Tonberry's named ability says that during its controller's turn it has first strike and deathtouch. Its Chef's Knife label prevented the existing turn-dependent keyword parser from recognizing the clause. The parser now recognizes that specific label with either an em dash or hyphen and reuses the existing current-controller keyword path.

On main `34ad266971d90e1b50a52b0db238fe5603b6f6b6`, actual paid casts for both seats reached ten assertions; both cases failed at the first missing keyword. An initial hyphen-only correction still failed four adjacent cases because the cached text uses an em dash; the corrected parser passes nine tests and 76 assertions. They cover both-seat paid casts, first-strike deathtouch combat against a tougher blocker, the opponent-turn blocking control, control transfer via a paid synthetic aura, JSON restoration and unrecognized labels/keywords. The control aura and combat opponent are fixtures, not newly certified cards.

The existing complete FIN Tonberry record has UUID `2dfac2a6-15a3-5f36-9adc-eb005e756cf1` and pool SHA-256 `96cffae6a671efe455003dcffbfc087061e576fd8126a548385457405ed16cfa`; the normalized shared fixture SHA-256 is `bec03f1b43174c16835a6978c4337c641a8108c3701bf1dab5c1d3a88d8ecf17`. The previous stun-entry evidence remains separate.

This addresses the recorded unread clause and the tested combat paths, not every interaction, general ability labels, continuous layers, newer printings or whole-card support. Historical recognition is not recalculated. There is no new save schema or corpus import. A rollback restores the old unread parser behavior. Held PRs #444–#452 require later integration and revalidation against current main.
