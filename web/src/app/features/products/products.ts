import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import {
  FormBuilder,
  FormControl,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { debounceTime, distinctUntilChanged } from 'rxjs';

import { AuthService } from '../../core/auth/auth.service';
import { Logo } from '../../shared/logo';
import { ApiErrorBody } from '../../core/auth/auth.models';
import { ProductService } from '../../core/products/product.service';
import {
  Product,
  ProductPage,
  SortColumn,
  SortDirection,
} from '../../core/products/product.models';

@Component({
  selector: 'app-products',
  imports: [ReactiveFormsModule, Logo],
  templateUrl: './products.html',
  styleUrl: './products.css',
})
export class Products implements OnInit {
  private readonly api = inject(ProductService);
  private readonly auth = inject(AuthService);

  protected readonly user = this.auth.user;

  protected readonly search = new FormControl('', { nonNullable: true });

  protected readonly items = signal<Product[]>([]);
  protected readonly total = signal(0);
  protected readonly page = signal(1);
  protected readonly pageCount = signal(0);
  protected readonly loading = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly busyId = signal<number | null>(null);

  protected readonly sort = signal<SortColumn>('name');
  protected readonly direction = signal<SortDirection>('asc');
  protected readonly lowStock = signal(false);
  protected readonly threshold = signal(10);
  protected readonly includeInactive = signal(false);

  protected readonly showAdd = signal(false);

  protected readonly addForm = inject(FormBuilder).nonNullable.group({
    sku: ['', [Validators.required]],
    name: ['', [Validators.required]],
    quantity: [0, [Validators.required, Validators.min(0)]],
  });

  protected readonly canPrev = computed(() => this.page() > 1);
  protected readonly canNext = computed(() => this.page() < this.pageCount());

  private readonly perPage = 25;

  ngOnInit(): void {
    this.search.valueChanges
      .pipe(debounceTime(300), distinctUntilChanged())
      .subscribe(() => {
        this.page.set(1);
        this.load();
      });

    this.load();
  }

  /**
   * @param keepError retains an existing message, for the resync that follows
   *                  a rejected change. Without it the reload would erase the
   *                  reason the change was rejected.
   */
  protected load(keepError = false): void {
    this.loading.set(true);

    if (!keepError) {
      this.error.set(null);
    }

    this.api
      .list({
        search: this.search.value.trim() || undefined,
        sort: this.sort(),
        direction: this.direction(),
        lowStock: this.lowStock(),
        threshold: this.lowStock() ? this.threshold() : undefined,
        includeInactive: this.includeInactive(),
        page: this.page(),
        perPage: this.perPage,
      })
      .subscribe({
        next: (result: ProductPage) => {
          this.items.set(result.items);
          this.total.set(result.total);
          this.page.set(result.page);
          this.pageCount.set(result.pageCount);
          this.loading.set(false);
        },
        error: (err: HttpErrorResponse) => {
          this.loading.set(false);
          this.error.set(this.readMessage(err));
        },
      });
  }

  protected sortBy(column: SortColumn): void {
    if (this.sort() === column) {
      this.direction.set(this.direction() === 'asc' ? 'desc' : 'asc');
    } else {
      this.sort.set(column);
      this.direction.set('asc');
    }

    this.page.set(1);
    this.load();
  }

  protected toggleLowStock(): void {
    this.lowStock.set(!this.lowStock());
    this.page.set(1);
    this.load();
  }

  /**
   * Takes the element so an out-of-range entry can be snapped back. Setting
   * only the signal would leave the field showing a value the filter is not
   * using. Below 1 is meaningless, since nothing can be under zero stock.
   */
  protected setThreshold(input: HTMLInputElement): void {
    const clamped = Math.max(1, Math.trunc(Number(input.value) || 1));

    input.value = String(clamped);

    if (clamped === this.threshold()) {
      return;
    }

    this.threshold.set(clamped);
    this.page.set(1);
    this.load();
  }

  protected toggleInactive(): void {
    this.includeInactive.set(!this.includeInactive());
    this.page.set(1);
    this.load();
  }

  protected goTo(page: number): void {
    this.page.set(page);
    this.load();
  }

  protected adjust(product: Product, delta: number): void {
    if (!product.isActive) {
      return;
    }

    this.mutate(product.id, this.api.adjust(product.id, delta));
  }

  /**
   * Anything not a whole number of zero or more reverts to the stored value,
   * so clearing the box cannot be read as "set this to nothing".
   */
  protected setQuantity(product: Product, input: HTMLInputElement): void {
    if (!product.isActive) {
      input.value = String(product.quantity);
      return;
    }

    const raw = input.value.trim();
    const parsed = Number(raw);
    const usable = raw !== '' && Number.isFinite(parsed) && parsed >= 0;
    const quantity = usable ? Math.trunc(parsed) : product.quantity;

    input.value = String(quantity);

    if (quantity === product.quantity) {
      return;
    }

    this.mutate(product.id, this.api.setQuantity(product.id, quantity));
  }

  protected remove(product: Product): void {
    this.mutate(product.id, this.api.remove(product.id), true);
  }

  /**
   * Reloads because a restored product may no longer belong on this page once
   * the retired filter is off.
   */
  protected restore(product: Product): void {
    this.mutate(product.id, this.api.restore(product.id), true);
  }

  protected add(): void {
    if (this.addForm.invalid) {
      this.addForm.markAllAsTouched();
      return;
    }

    const { sku, name, quantity } = this.addForm.getRawValue();

    this.error.set(null);
    this.loading.set(true);

    this.api.create(sku, name, Number(quantity)).subscribe({
      next: (created) => {
        this.addForm.reset({ sku: '', name: '', quantity: 0 });
        this.showAdd.set(false);
        // Jump to the new row rather than leaving the user on a page that
        // does not contain it.
        this.search.setValue(created.sku);
      },
      error: (err: HttpErrorResponse) => {
        this.loading.set(false);
        this.error.set(this.readMessage(err));
      },
    });
  }

  protected logout(): void {
    this.auth.logout();
  }

  protected trackById(_index: number, product: Product): number {
    return product.id;
  }

  /**
   * Replaces the row with whatever the server stored, so the displayed value is
   * never a local guess.
   */
  private mutate(
    id: number,
    request: ReturnType<ProductService['adjust']>,
    reload = false,
  ): void {
    this.busyId.set(id);
    this.error.set(null);

    request.subscribe({
      next: (updated: Product) => {
        this.busyId.set(null);

        if (reload) {
          this.load();
          return;
        }

        this.items.update((rows) =>
          rows.map((row) => (row.id === updated.id ? updated : row)),
        );
      },
      error: (err: HttpErrorResponse) => {
        this.busyId.set(null);
        this.error.set(this.readMessage(err));
        // The server rejected the change, so re-read rather than leave a
        // stale row on screen, but keep the reason on screen.
        this.load(true);
      },
    });
  }

  private readMessage(error: HttpErrorResponse): string {
    if (error.status === 0) {
      return 'Cannot reach the server.';
    }

    const body = error.error as ApiErrorBody | null;

    return body?.error?.message ?? 'Something went wrong. Please try again.';
  }
}
