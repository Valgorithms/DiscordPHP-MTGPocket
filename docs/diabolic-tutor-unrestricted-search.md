# Diabolic Tutor unrestricted search

This milestone will verify the complete cached M12 Diabolic Tutor record (`87ae0da5-88f9-503f-a3fc-48b02eca0dc0`, MTGJSON `5.3.0+20261022`). Its normalized fixture SHA-256 is `1b57f697a358559f870e1690fbfef69bc0d6ee50cdbf5f659bbd19551abdd1bb`.

Start with an actual paid two-generic, two-black cast. Verify that an unrestricted search requires a selection from a nonempty library, permits every card type, moves the chosen card to hand and shuffles once. Test both seats, empty libraries, invalid actions, paid countering, save/load, continuation, existing practice selection and persisted panels. The source route appears to exist; no runtime defect is established yet.

Use serial bounded offline checks, pinned source evidence and exact CI before merge. No new corpus scan or source fetch, import, live service, bot build/restart or deployment. Recognition, scoped functionality and whole-card certification remain separate. No environment contract change is planned.
