import { Component, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import {
  AbstractControl,
  FormBuilder,
  ReactiveFormsModule,
  ValidationErrors,
  Validators,
} from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { switchMap } from 'rxjs';

import { AuthService } from '../../core/auth/auth.service';
import { ApiErrorBody } from '../../core/auth/auth.models';

/** Mirrors the API: min 12 characters, max 64 for the username. */
export const PASSWORD_MIN_LENGTH = 12;
const USERNAME_MAX_LENGTH = 64;

function passwordsMatch(group: AbstractControl): ValidationErrors | null {
  const password = group.get('password')?.value;
  const confirm = group.get('confirmPassword')?.value;

  return password === confirm ? null : { passwordMismatch: true };
}

@Component({
  selector: 'app-register',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './register.html',
  styleUrls: ['../auth-card.css'],
})
export class Register {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly passwordMinLength = PASSWORD_MIN_LENGTH;
  protected readonly submitting = signal(false);
  protected readonly errorMessage = signal<string | null>(null);

  protected readonly form = inject(FormBuilder).nonNullable.group(
    {
      username: [
        '',
        [Validators.required, Validators.maxLength(USERNAME_MAX_LENGTH)],
      ],
      password: [
        '',
        [Validators.required, Validators.minLength(PASSWORD_MIN_LENGTH)],
      ],
      confirmPassword: ['', [Validators.required]],
    },
    { validators: passwordsMatch },
  );

  protected submit(): void {
    if (this.form.invalid || this.submitting()) {
      this.form.markAllAsTouched();
      return;
    }

    this.submitting.set(true);
    this.errorMessage.set(null);

    const { username, password } = this.form.getRawValue();

    // Registration returns the account, not a token, so sign in afterwards.
    this.auth
      .register(username, password)
      .pipe(switchMap(() => this.auth.login(username, password)))
      .subscribe({
        next: () => {
          void this.router.navigate(['/products']);
        },
        error: (error: HttpErrorResponse) => {
          this.submitting.set(false);
          this.errorMessage.set(this.readMessage(error));
        },
      });
  }

  private readMessage(error: HttpErrorResponse): string {
    if (error.status === 0) {
      return 'Cannot reach the server. Is the API running?';
    }

    const body = error.error as ApiErrorBody | null;

    return body?.error?.message ?? 'Something went wrong. Please try again.';
  }
}
