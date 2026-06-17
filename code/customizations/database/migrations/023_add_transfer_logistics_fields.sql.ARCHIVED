-- เพิ่มข้อมูลโลจิสติกส์การโอนสต็อก: คนขนของ, ทะเบียนรถ, น้ำหนักรับจริง, หมายเหตุตรวจรับ
ALTER TABLE stock_transfers
  ADD COLUMN transporter_name VARCHAR(100) DEFAULT NULL AFTER note,
  ADD COLUMN vehicle_plate    VARCHAR(20)  DEFAULT NULL AFTER transporter_name,
  ADD COLUMN received_weight_kg DECIMAL(12,3) DEFAULT NULL AFTER vehicle_plate,
  ADD COLUMN receive_note    VARCHAR(500) DEFAULT NULL AFTER received_weight_kg;
