import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { Observable, tap } from 'rxjs';

import { AuthUser, LoginResponse } from './auth.models';

const TOKEN_KEY = 'inventory-tracker.token';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);

  private readonly currentUser = signal<AuthUser | null>(null);

  readonly user = this.currentUser.asReadonly();
  readonly isAuthenticated = computed(() => this.currentUser() !== null);

  token(): string | null {
    return localStorage.getItem(TOKEN_KEY);
  }

  hasToken(): boolean {
    return this.token() !== null;
  }

  login(username: string, password: string): Observable<LoginResponse> {
    return this.http
      .post<LoginResponse>('/api/auth/login', { username, password })
      .pipe(
        tap((response) => {
          localStorage.setItem(TOKEN_KEY, response.token);
          this.currentUser.set(response.user);
        }),
      );
  }

  register(username: string, password: string): Observable<AuthUser> {
    return this.http.post<AuthUser>('/api/auth/register', { username, password });
  }

  /** Resolves the account behind the stored token; the guard uses it to validate. */
  me(): Observable<AuthUser> {
    return this.http
      .get<AuthUser>('/api/auth/me')
      .pipe(tap((user) => this.currentUser.set(user)));
  }

  logout(): void {
    localStorage.removeItem(TOKEN_KEY);
    this.currentUser.set(null);
    void this.router.navigate(['/login']);
  }
}
