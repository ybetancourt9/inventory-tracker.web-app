import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { catchError, map, of } from 'rxjs';

import { AuthService } from './auth.service';

/**
 * Allows the route only when the stored token is still accepted by the API.
 * A token present in localStorage proves nothing; it may be expired or revoked.
 */
export const authGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (!auth.hasToken()) {
    return router.createUrlTree(['/login']);
  }

  if (auth.isAuthenticated()) {
    return true;
  }

  return auth.me().pipe(
    map(() => true),
    catchError(() => {
      auth.logout();
      return of(router.createUrlTree(['/login']));
    }),
  );
};
