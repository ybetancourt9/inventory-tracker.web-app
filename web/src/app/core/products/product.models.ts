export interface Product {
  id: number;
  sku: string;
  name: string;
  quantity: number;
  isActive: boolean;
  updatedAt: string;
}

export interface ProductPage {
  items: Product[];
  page: number;
  perPage: number;
  total: number;
  pageCount: number;
}

export type SortColumn = 'name' | 'sku' | 'quantity' | 'updatedAt';
export type SortDirection = 'asc' | 'desc';

export interface ProductQuery {
  search?: string;
  sort?: SortColumn;
  direction?: SortDirection;
  lowStock?: boolean;
  threshold?: number;
  includeInactive?: boolean;
  page?: number;
  perPage?: number;
}
