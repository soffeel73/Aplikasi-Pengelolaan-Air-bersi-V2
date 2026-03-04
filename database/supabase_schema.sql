-- ============================================================
-- FULL DATABASE SCHEMA: smart_air_desa (PostgreSQL / Supabase)
-- Migrated from MySQL by Antigravity
-- Date: 2026-03-05
-- ============================================================

-- Helper: auto-update updated_at trigger function
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = NOW();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- 1. Table: pelanggans
CREATE TABLE IF NOT EXISTS pelanggans (
  id SERIAL PRIMARY KEY,
  customer_id VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  address TEXT NOT NULL,
  type TEXT NOT NULL CHECK (type IN ('R1', 'R2', 'N1', 'S1')),
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_pelanggans_customer_id ON pelanggans(customer_id);

CREATE TRIGGER trg_pelanggans_updated_at
  BEFORE UPDATE ON pelanggans
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- 2. Table: input_meters
CREATE TABLE IF NOT EXISTS input_meters (
  id SERIAL PRIMARY KEY,
  pelanggan_id INT NOT NULL,
  period_year INT NOT NULL,
  period_month INT NOT NULL,
  meter_awal INT DEFAULT 0,
  meter_akhir INT NOT NULL,
  jumlah_pakai INT NOT NULL,
  total_biaya DECIMAL(15,2) NOT NULL,
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW(),
  CONSTRAINT fk_input_meters_pelanggan FOREIGN KEY (pelanggan_id) REFERENCES pelanggans(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_input_meters_pelanggan_period ON input_meters(pelanggan_id, period_year, period_month);

CREATE TRIGGER trg_input_meters_updated_at
  BEFORE UPDATE ON input_meters
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- 3. Table: tagihans
CREATE TABLE IF NOT EXISTS tagihans (
  id SERIAL PRIMARY KEY,
  pelanggan_id INT NOT NULL,
  input_meter_id INT NOT NULL,
  month VARCHAR(7) NOT NULL,
  initial_meter INT DEFAULT 0,
  final_meter INT NOT NULL,
  usage_amount INT NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  tunggakan DECIMAL(15,2) DEFAULT 0.00,
  total_tagihan DECIMAL(15,2) NOT NULL,
  status TEXT DEFAULT 'unpaid' CHECK (status IN ('paid', 'unpaid')),
  paid_at TIMESTAMP DEFAULT NULL,
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW(),
  CONSTRAINT fk_tagihans_pelanggan FOREIGN KEY (pelanggan_id) REFERENCES pelanggans(id) ON DELETE CASCADE,
  CONSTRAINT fk_tagihans_input_meter FOREIGN KEY (input_meter_id) REFERENCES input_meters(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_tagihan_pelanggan ON tagihans(pelanggan_id);
CREATE INDEX IF NOT EXISTS idx_tagihan_status ON tagihans(status);

CREATE TRIGGER trg_tagihans_updated_at
  BEFORE UPDATE ON tagihans
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- 4. Table: transaksis
CREATE TABLE IF NOT EXISTS transaksis (
  id SERIAL PRIMARY KEY,
  tagihan_id INT DEFAULT NULL,
  tipe TEXT NOT NULL CHECK (tipe IN ('pemasukan', 'pengeluaran')),
  nama VARCHAR(255) NOT NULL,
  kategori VARCHAR(100) NOT NULL,
  nominal DECIMAL(15,2) NOT NULL,
  tanggal DATE NOT NULL,
  keterangan TEXT NULL,
  period_year INT NOT NULL,
  period_month INT NOT NULL,
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_transaksis_period ON transaksis(period_year, period_month);
CREATE INDEX IF NOT EXISTS idx_transaksis_tipe ON transaksis(tipe);
CREATE INDEX IF NOT EXISTS idx_transaksis_tanggal ON transaksis(tanggal);
CREATE INDEX IF NOT EXISTS idx_transaksis_kategori ON transaksis(kategori);

CREATE TRIGGER trg_transaksis_updated_at
  BEFORE UPDATE ON transaksis
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- 5. Table: keluhans
CREATE TABLE IF NOT EXISTS keluhans (
  id SERIAL PRIMARY KEY,
  nama_lengkap VARCHAR(255) NOT NULL,
  no_hpm VARCHAR(50) NOT NULL,
  kategori TEXT NOT NULL CHECK (kategori IN ('Pipa Bocor', 'Air Tidak Mengalir', 'Meteran Rusak', 'Kesalahan Tagihan', 'Kualitas Air', 'Lainnya')),
  detail_laporan TEXT NOT NULL,
  foto_bukti VARCHAR(500) NULL,
  no_whatsapp VARCHAR(20) NOT NULL,
  status TEXT DEFAULT 'Menunggu' CHECK (status IN ('Menunggu', 'Diproses', 'Selesai')),
  catatan_admin TEXT NULL,
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_keluhans_no_hpm ON keluhans(no_hpm);
CREATE INDEX IF NOT EXISTS idx_keluhans_status ON keluhans(status);

CREATE TRIGGER trg_keluhans_updated_at
  BEFORE UPDATE ON keluhans
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- 6. Table: beritas
CREATE TABLE IF NOT EXISTS beritas (
  id SERIAL PRIMARY KEY,
  judul VARCHAR(255) NOT NULL,
  foto_url VARCHAR(500),
  ringkasan TEXT,
  isi_berita TEXT,
  tanggal_publish DATE,
  status TEXT DEFAULT 'published' CHECK (status IN ('draft', 'published')),
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TRIGGER trg_beritas_updated_at
  BEFORE UPDATE ON beritas
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- 7. Table: pengumumans
CREATE TABLE IF NOT EXISTS pengumumans (
  id SERIAL PRIMARY KEY,
  teks VARCHAR(500) NOT NULL,
  status TEXT DEFAULT 'aktif' CHECK (status IN ('aktif', 'non-aktif')),
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TRIGGER trg_pengumumans_updated_at
  BEFORE UPDATE ON pengumumans
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- 8. Table: pengurus
CREATE TABLE IF NOT EXISTS pengurus (
  id SERIAL PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  jabatan VARCHAR(100) NOT NULL,
  foto_url VARCHAR(255) DEFAULT NULL,
  urutan INT DEFAULT 0,
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMPTZ DEFAULT NOW()
);

-- 9. Table: galeris
CREATE TABLE IF NOT EXISTS galeris (
  id SERIAL PRIMARY KEY,
  image_path VARCHAR(255) NOT NULL,
  judul VARCHAR(255) DEFAULT NULL,
  caption TEXT DEFAULT NULL,
  kategori VARCHAR(100) DEFAULT 'Kegiatan',
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TRIGGER trg_galeris_updated_at
  BEFORE UPDATE ON galeris
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();
