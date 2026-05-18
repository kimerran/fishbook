# Multi-stage Dockerfile for the Fishbook frontend.
# Dev: docker compose mounts the source and runs `npm run dev`.
# Prod: future slice 1.5 uses the `runner` stage.

FROM node:26-alpine AS base
WORKDIR /app

# --- Dependencies stage ---
FROM base AS deps
COPY package.json package-lock.json ./
RUN npm ci

# --- Builder stage ---
FROM base AS builder
COPY --from=deps /app/node_modules ./node_modules
COPY . .
RUN npm run build

# --- Runner stage (prod) ---
FROM base AS runner
ENV NODE_ENV=production
COPY --from=builder /app/.next ./.next
COPY --from=builder /app/public ./public
COPY --from=builder /app/package.json /app/package-lock.json ./
COPY --from=builder /app/node_modules ./node_modules
EXPOSE 3000
CMD ["npm", "run", "start"]
