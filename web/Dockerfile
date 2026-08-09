# Build context is the repository root so this can reach docker/nginx/.
# Node is a build-time dependency only; the runtime image is nginx.
FROM node:24.18.0-alpine AS build

WORKDIR /app

COPY web/package.json web/package-lock.json ./
RUN npm ci

COPY web/ ./
RUN npx ng build --configuration production


FROM nginx:1.30.4-alpine AS prod

COPY --from=build /app/dist/web/browser /usr/share/nginx/html
COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
