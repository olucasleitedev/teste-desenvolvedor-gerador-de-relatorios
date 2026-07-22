export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
  status?: number;
}

export interface User {
  id: number;
  name: string;
  email: string;
}

export interface PaginatedMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta?: PaginatedMeta;
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
  links?: unknown;
}

export interface Customer {
  id: number;
  name: string;
  document: string;
  email: string;
  status: "active" | "inactive";
  created_at?: string;
  updated_at?: string;
}

export interface Billing {
  id: number;
  customer_id: number;
  customer?: Customer;
  description: string;
  original_amount: string;
  interest_amount: string;
  updated_amount: string;
  issue_date: string;
  due_date: string;
  payment_date?: string | null;
  monthly_interest_rate: string;
  status: "pending" | "overdue" | "paid" | "cancelled";
  paid_amount?: string | null;
  interest_amount_at_payment?: string | null;
  created_at?: string;
  updated_at?: string;
}

export interface ReportTotals {
  count: number;
  original_total: string;
  interest_total: string;
  updated_total: string;
  received_total: string;
  pending_total: string;
}

export interface BillingReportResponse extends PaginatedResponse<Billing> {
  totals: ReportTotals;
  filters?: Record<string, string | number>;
}

export interface ReportExport {
  id: string;
  format: "csv" | "pdf";
  status: "pending" | "processing" | "completed" | "failed";
  filters?: Record<string, string | number>;
  row_count?: number | null;
  error_message?: string | null;
  started_at?: string | null;
  completed_at?: string | null;
  created_at?: string;
  download_url?: string | null;
}

export interface ReportExportResponse {
  data: ReportExport;
}

export function paginationOf<T>(response: PaginatedResponse<T>) {
  return {
    page: response.meta?.current_page ?? response.current_page ?? 1,
    lastPage: response.meta?.last_page ?? response.last_page ?? 1,
    perPage: response.meta?.per_page ?? response.per_page ?? 15,
    total: response.meta?.total ?? response.total ?? response.data.length,
  };
}
