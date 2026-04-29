/*
 * auth.config.ts
 *
 * Custom JWT provider for the Cardify admin live-analytics surface.
 * PHP mints HS256 JWTs signed with CONVEX_AUTH_SECRET (shared with the backend
 * via .env.convex). The browser passes the JWT to ConvexReactClient; Convex
 * validates here, then `lib/identity.ts::requireIdentity()` reads claims.
 *
 * The JWT issuer is "cardify-admin" and the audience is "convex-cardify".
 * Tokens are short-lived (10 minutes) and refreshed on the next page load.
 */

export default {
  providers: [
    {
      type: "customJwt",
      issuer: "cardify-admin",
      jwks: undefined as unknown as string,
      // Self-hosted Convex consults CONVEX_AUTH_SECRET env var when jwks is
      // omitted. The backend reads the shared secret and verifies HS256.
      algorithm: "HS256",
      applicationID: "convex-cardify",
    },
  ],
};
