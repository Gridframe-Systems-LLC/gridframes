# Soul-bound Sui identity

How a customer — an agent with its own wallet — proves a Sui address it owns and binds it to its
account, and what the platform does with the proof. Shipped in 4.4.0.

## The property being bought

An address binds to one team, ever. The private key never leaves its owner: the customer signs a
challenge the platform wrote, and the platform keeps only the address and public key. Unbinding
releases the address for its own team alone (key rotation works); no other team can ever bind it.
This is a platform-level bond — the chain is not consulted for the proof.

## The protocol

1. `POST /api/v1/team/sui/challenge` (bearer token, `write` ability) — the platform writes a
   challenge with a 16-byte nonce and holds its SHA-256 in the cache for
   `SUI_CHALLENGE_TTL_MINUTES` (15 by default). One outstanding challenge per team.
2. The customer signs the exact challenge bytes as a Sui **personal message** with the ed25519 key
   behind the address. Every Sui SDK's `signPersonalMessage` does this: blake2b-256 over
   `intent [3,0,0] ‖ bcs(bytes)` (length in LEB128, then the bytes), signature as
   `flag ‖ signature ‖ public key` in base64.
3. `POST /api/v1/team/sui/verify` with `address`, `message`, `signature`. The platform checks the
   challenge byte-for-byte, verifies the signature with libsodium, derives the address from the
   public key (`blake2b256(0x00 ‖ key)`) and refuses when it differs from the claimed address.
   On any outcome the challenge is consumed — a guess cannot be ground against it.
4. `GET /api/v1/team/sui` lists the team's bound addresses; `DELETE /api/v1/team/sui/{id}` unbinds.

The same flow is available in the account UI on the subscription page.

## What is stored

`sui_identities`: team, address (unique globally — that is the bond), public key, an optional
SuiNS name, `verified_at`, and `unbound_at` once released. Rows are never deleted; the unique
index on the address is what makes "one team, ever" true. A team may hold at most
`SUI_MAX_IDENTITIES_PER_TEAM` (5 by default) live addresses.

## SuiNS

The address's `.sui` name is resolved through mainnet GraphQL for display only, cached an hour,
and is never proof of anything. A network that does not answer reads as no name.

## Configuration

`config/sui.php`: `SUI_GRAPHQL_URL` (default `https://graphql.mainnet.sui.io/graphql`),
`SUI_CHALLENGE_TTL_MINUTES`, `SUI_MAX_IDENTITIES_PER_TEAM`. No keys, no RPC credentials, no
smart contract: the whole feature is off-chain verification and costs nothing to run.

## Why no Move contract (yet)

Nothing in the product needs on-chain state today: the proof is a signature, the bond is a unique
row. If an on-chain registry of platform citizens is wanted later, a non-transferable capability
object per identity is the design to grow into; the verifier here already proves exactly the
address such an object would name.
