# 1. Team Keywords are single words; phrases deferred

Date: 2026-09-01

## Status

Accepted

## Context

A Team Keyword is a word or phrase a team wants flagged when it appears in a
session transcript (see `CONTEXT.md` — **Team Keyword**, **Category**). The
callout examples the domain is grounded in are phrases: "enemy is planting",
"smoking heaven and ct". The original ER model carried an `is_phrase` flag on
`team_keywords` to distinguish the two.

Detection in this prototype works at the transcript-token level: it matches a
configured keyword against individual words as they are transcribed. Phrase
matching — ordering, gaps, partial hits, fuzzy boundaries — is a materially
different problem and is not needed to validate the framework's core premise
(that communication frequency, absence, and game-state alignment are useful
insights).

## Decision

A Team Keyword is exactly one word — no whitespace. The
`PUT /teams/settings/keywords` endpoint rejects any entry containing interior
whitespace. `is_phrase` is dropped from the schema and model. `CONTEXT.md`
adds `phrase` to the **Team Keyword** _Avoid_ list.

## Consequences

- Detection stays token-level: no phrase-matching strategy to design or tune now.
- A future phrase feature needs its own migration (re-introducing a phrase
  representation) and a matching approach; it is not a config change.
- Teams expressing a phrase callout must approximate it with its salient word
  (e.g. `planting` for "enemy is planting").
