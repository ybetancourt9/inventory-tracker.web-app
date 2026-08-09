import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { Product, ProductPage, ProductQuery } from './product.models';

const BASE = '/api/products';

@Injectable({ providedIn: 'root' })
export class ProductService {
  private readonly http = inject(HttpClient);

  list(query: ProductQuery): Observable<ProductPage> {
    let params = new HttpParams();

    for (const [key, value] of Object.entries(query)) {
      // Omit empty values so the API applies its own defaults.
      if (value !== undefined && value !== null && value !== '' && value !== false) {
        params = params.set(key, String(value));
      }
    }

    return this.http.get<ProductPage>(BASE, { params });
  }

  create(sku: string, name: string, quantity: number): Observable<Product> {
    return this.http.post<Product>(BASE, { sku, name, quantity });
  }

  setQuantity(id: number, quantity: number): Observable<Product> {
    return this.http.patch<Product>(`${BASE}/${id}`, { quantity });
  }

  rename(id: number, name: string): Observable<Product> {
    return this.http.patch<Product>(`${BASE}/${id}`, { name });
  }

  /** Relative change, so concurrent adjustments do not overwrite each other. */
  adjust(id: number, delta: number): Observable<Product> {
    return this.http.patch<Product>(`${BASE}/${id}/quantity`, { delta });
  }

  remove(id: number): Observable<Product> {
    return this.http.delete<Product>(`${BASE}/${id}`);
  }

  restore(id: number): Observable<Product> {
    return this.http.post<Product>(`${BASE}/${id}/restore`, {});
  }
}
