# Hosting decision: Kaduna eDQA Portal

> Template. Copy the blanks in once a candidate server has been checked, then send for sign-off.
> Background: `DEPLOY.md` §2.3 (sizing) and §4 (hosting decision).

| | |
|---|---|
| Status | Draft / Proposed / **Agreed** |
| Prepared by | |
| Date | |

## 1. What we are asking you to agree

The portal runs as Docker containers on a virtual private server (VPS) that we control. Please
confirm the provider, region, cost and the practical answers in section 4, then sign section 6.

## 2. Recommended hosting

| | Production | Staging |
|---|---|---|
| Provider | | |
| Plan / server type | | |
| Region (data centre) | | |
| vCPU / RAM / disk | 4 vCPU / 8 GB / 120 GB SSD (minimum) | 2 vCPU / 4 GB / 60 GB SSD (minimum) |
| Operating system | Ubuntu 24.04 LTS | Ubuntu 24.04 LTS |
| Monthly cost (currency) | | |
| Billing: who pays, and how | | |
| Data residency requirement? | Yes / No. If yes, which rule: | |

Off-site backup storage:

| | |
|---|---|
| Provider / bucket | |
| Region | |
| Monthly cost | |

**Total monthly cost:** ____ (production + staging + backup storage)

Not supported: cPanel/WHM or shared hosting. cPanel's services, firewall and port ownership
conflict with a Docker edge proxy. If only a cPanel server exists, we provision a separate small VPS.

## 3. Server check results

Each candidate server was checked with `discovery/hosting_check.sh`. Reports are in
`discovery/out/hosting_<name>.md`.

| Server | Report | Result (PASS / WARN / FAIL) | Blockers |
|---|---|---|---|
| | `out/hosting_….md` | | |

## 4. Week-one answers (`DEPLOY.md` §4)

| # | Question | Answer | Evidence / who confirmed |
|---|---|---|---|
| 1 | Does the provider allow Docker? (KVM virtualisation, not OpenVZ/LXC; no kernel restrictions) | | hosting_check §1 Virtualisation |
| 2 | Kernel ≥ 5.15, cgroups v2, overlay2 storage driver? | | hosting_check §2–3 |
| 3a | Outbound HTTPS to ODK Central (`…`)? | | hosting_check §6 |
| 3b | Outbound HTTPS to GitHub Container Registry (`ghcr.io`)? | | hosting_check §6 |
| 3c | Outbound to the SMTP relay (`host:port`)? Provider's outbound-mail policy? | | hosting_check §6 |
| 3d | Outbound HTTPS to the backup storage endpoint? | | hosting_check §6 |
| 4 | Ports 80 and 443 (TCP, plus UDP 443 for HTTP/3) reachable from the internet? | | tested from outside with `nc -vz` |
| 5a | Domain: which domain, and who owns the DNS? | `edqa.…` / `staging.edqa.…`; owner: | |
| 5b | Who pays for hosting? | | |

Related week-one items (`DEPLOY.md` §21.1):

- [ ] ODK Central service account (Project Viewer role) created by the client
- [ ] Off-site backup bucket created
- [ ] Holders of the deployment vault password named: ____, ____

## 5. Decision

We will host the Kaduna eDQA Portal on **____** in **____**, at **____ per month**, paid by
**____**. Any conditions or exceptions:

-

## 6. Sign-off

For the client (Kaduna State):

| Name | Role / organisation | Signature | Date |
|---|---|---|---|
| | | | |

For the delivery team:

| Name | Role | Signature | Date |
|---|---|---|---|
| | | | |
