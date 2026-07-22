"use client";

import { FormEvent, useEffect, useState } from "react";
import { Alert, Field, Loading, PageTitle, Pagination, SelectField } from "@/components/ui";
import { api, download, money, pollReportExport, queryString, queueReportExport } from "@/lib/api";
import type {
  ApiError,
  Billing,
  BillingReportResponse,
  Customer,
  PaginatedResponse,
  ReportExport,
  ReportTotals,
} from "@/types/api";
import { paginationOf } from "@/types/api";

const statusLabel: Record<string, string> = {
  pending: "Pendente",
  overdue: "Vencida",
  paid: "Paga",
  cancelled: "Cancelada",
};

const emptyTotals: ReportTotals = {
  count: 0,
  original_total: "0.00",
  interest_total: "0.00",
  updated_total: "0.00",
  received_total: "0.00",
  pending_total: "0.00",
};

export default function ReportsPage() {
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [items, setItems] = useState<Billing[]>([]);
  const [totals, setTotals] = useState<ReportTotals>(emptyTotals);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [exporting, setExporting] = useState<"csv" | "pdf" | null>(null);
  const [queueExporting, setQueueExporting] = useState<"csv" | "pdf" | null>(null);
  const [queuedExport, setQueuedExport] = useState<ReportExport | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [filters, setFilters] = useState({
    date_from: "",
    date_to: "",
    customer_id: "",
    status: "",
    date_field: "issue_date",
    sort_by: "due_date",
    sort_dir: "asc",
  });

  useEffect(() => {
    api<PaginatedResponse<Customer>>("/customers?per_page=100&sort_by=name")
      .then((response) => setCustomers(response.data))
      .catch(() => undefined);
  }, []);

  async function loadReport(nextPage = page) {
    setLoading(true);
    setError(null);
    try {
      const response = await api<BillingReportResponse>(
        `/reports/billing${queryString({ ...filters, page: nextPage, per_page: 15 })}`,
      );
      setItems(response.data);
      setTotals(response.totals ?? emptyTotals);
      const meta = paginationOf(response);
      setPage(meta.page);
      setLastPage(meta.lastPage);
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadReport(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function onFilterSubmit(event: FormEvent) {
    event.preventDefault();
    setPage(1);
    loadReport(1);
  }

  async function onExport(type: "csv" | "pdf") {
    setExporting(type);
    setError(null);
    setSuccess(null);
    try {
      await download(
        `/reports/billing/export/${type}${queryString(filters)}`,
        `relatorio-faturamento.${type}`,
      );
      setSuccess(`Exportação ${type.toUpperCase()} concluída.`);
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setExporting(null);
    }
  }

  async function onQueueExport(type: "csv" | "pdf") {
    setQueueExporting(type);
    setQueuedExport(null);
    setError(null);
    setSuccess(null);

    try {
      const queued = await queueReportExport(type, filters);
      setQueuedExport(queued.data);
      setSuccess(`Exportação ${type.toUpperCase()} enfileirada. Aguardando processamento…`);

      const completed = await pollReportExport(queued.data.id, setQueuedExport);

      if (completed.download_url) {
        await download(completed.download_url, `relatorio-faturamento.${type}`);
      }

      setSuccess(
        `Exportação ${type.toUpperCase()} concluída${completed.row_count ? ` (${completed.row_count} linhas)` : ""}.`,
      );
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setQueueExporting(null);
    }
  }

  return (
    <div>
      <PageTitle title="Relatório de faturamento" />
      <Alert error={error} success={success} />

      <form onSubmit={onFilterSubmit} className="mb-6 grid gap-3 rounded-lg border border-slate-200 bg-white p-4 md:grid-cols-3">
        <Field
          label="Data inicial"
          type="date"
          value={filters.date_from}
          onChange={(e) => setFilters({ ...filters, date_from: e.target.value })}
        />
        <Field
          label="Data final"
          type="date"
          value={filters.date_to}
          onChange={(e) => setFilters({ ...filters, date_to: e.target.value })}
        />
        <SelectField
          label="Campo de data"
          value={filters.date_field}
          onChange={(e) => setFilters({ ...filters, date_field: e.target.value })}
        >
          <option value="issue_date">Emissão</option>
          <option value="due_date">Vencimento</option>
          <option value="payment_date">Pagamento</option>
        </SelectField>
        <SelectField
          label="Cliente"
          value={filters.customer_id}
          onChange={(e) => setFilters({ ...filters, customer_id: e.target.value })}
        >
          <option value="">Todos</option>
          {customers.map((customer) => (
            <option key={customer.id} value={customer.id}>
              {customer.name}
            </option>
          ))}
        </SelectField>
        <SelectField
          label="Status"
          value={filters.status}
          onChange={(e) => setFilters({ ...filters, status: e.target.value })}
        >
          <option value="">Todos</option>
          <option value="pending">Pendente</option>
          <option value="overdue">Vencida</option>
          <option value="paid">Paga</option>
          <option value="cancelled">Cancelada</option>
        </SelectField>
        <SelectField
          label="Ordenar por"
          value={filters.sort_by}
          onChange={(e) => setFilters({ ...filters, sort_by: e.target.value })}
        >
          <option value="due_date">Vencimento</option>
          <option value="issue_date">Emissão</option>
          <option value="original_amount">Valor original</option>
          <option value="status">Status</option>
        </SelectField>
        <div className="flex flex-wrap items-end gap-2 md:col-span-3">
          <button type="submit" className="btn-primary">
            Aplicar filtros
          </button>
          <button type="button" className="btn-secondary" onClick={() => onExport("csv")} disabled={!!exporting || !!queueExporting}>
            {exporting === "csv" ? "Exportando…" : "Exportar CSV"}
          </button>
          <button type="button" className="btn-secondary" onClick={() => onExport("pdf")} disabled={!!exporting || !!queueExporting}>
            {exporting === "pdf" ? "Exportando…" : "Exportar PDF"}
          </button>
          <button type="button" className="btn-secondary" onClick={() => onQueueExport("csv")} disabled={!!exporting || !!queueExporting}>
            {queueExporting === "csv" ? "Processando fila…" : "CSV em fila"}
          </button>
          <button type="button" className="btn-secondary" onClick={() => onQueueExport("pdf")} disabled={!!exporting || !!queueExporting}>
            {queueExporting === "pdf" ? "Processando fila…" : "PDF em fila"}
          </button>
        </div>
      </form>

      {queuedExport && queueExporting && (
        <div className="mb-4 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-700">
          Exportação em fila: <strong>{queuedExport.status}</strong>
          {queuedExport.row_count ? ` · ${queuedExport.row_count} linhas` : ""}
        </div>
      )}

      <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <TotalCard label="Quantidade" value={String(totals.count)} />
        <TotalCard label="Valor original" value={money(totals.original_total)} />
        <TotalCard label="Total de juros" value={money(totals.interest_total)} />
        <TotalCard label="Valor atualizado" value={money(totals.updated_total)} />
        <TotalCard label="Total recebido" value={money(totals.received_total)} />
        <TotalCard label="Total pendente" value={money(totals.pending_total)} />
      </div>

      {loading ? (
        <Loading />
      ) : (
        <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
          <table className="min-w-full text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-600">
              <tr>
                <th className="px-3 py-3">Cliente</th>
                <th className="px-3 py-3">Descrição</th>
                <th className="px-3 py-3">Emissão</th>
                <th className="px-3 py-3">Vencimento</th>
                <th className="px-3 py-3">Status</th>
                <th className="px-3 py-3">Original</th>
                <th className="px-3 py-3">Juros</th>
                <th className="px-3 py-3">Atualizado</th>
                <th className="px-3 py-3">Pago</th>
              </tr>
            </thead>
            <tbody>
              {items.map((billing) => (
                <tr key={billing.id} className="border-b border-slate-100">
                  <td className="px-3 py-3">{billing.customer?.name ?? "-"}</td>
                  <td className="px-3 py-3">{billing.description}</td>
                  <td className="px-3 py-3">{billing.issue_date}</td>
                  <td className="px-3 py-3">{billing.due_date}</td>
                  <td className="px-3 py-3">{statusLabel[billing.status] ?? billing.status}</td>
                  <td className="px-3 py-3">{money(billing.original_amount)}</td>
                  <td className="px-3 py-3">{money(billing.interest_amount)}</td>
                  <td className="px-3 py-3">{money(billing.updated_amount)}</td>
                  <td className="px-3 py-3">{billing.paid_amount ? money(billing.paid_amount) : "-"}</td>
                </tr>
              ))}
              {items.length === 0 && (
                <tr>
                  <td colSpan={9} className="px-4 py-8 text-center text-slate-500">
                    Nenhum registro para os filtros informados.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}
      <Pagination
        page={page}
        lastPage={lastPage}
        onPage={(next) => {
          setPage(next);
          loadReport(next);
        }}
      />
    </div>
  );
}

function TotalCard({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-4">
      <div className="text-xs uppercase tracking-wide text-slate-500">{label}</div>
      <div className="mt-1 text-lg font-semibold text-slate-900">{value}</div>
    </div>
  );
}
