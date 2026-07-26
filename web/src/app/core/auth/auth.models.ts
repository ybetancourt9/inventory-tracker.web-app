export interface AuthUser {
  id: number;
  username: string;
}

export interface LoginResponse {
  tokenType: string;
  token: string;
  expiresIn: number;
  user: AuthUser;
}

/** Shape of a Restler error body. */
export interface ApiErrorBody {
  error?: { code?: number; message?: string };
}
