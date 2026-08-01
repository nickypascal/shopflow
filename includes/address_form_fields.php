<?php
/** @var array $address */
/** @var string $submitLabel */
/** @var string $returnTo */
?>
<div class="address-form-grid">
    <label>
        <span>Label Alamat</span>
        <input type="text" name="label" maxlength="50" list="address-label-options" value="<?= e((string) $address['label']) ?>" placeholder="Contoh: Rumah" required>
        <datalist id="address-label-options">
            <option value="Rumah">
            <option value="Kantor">
            <option value="Kos">
            <option value="Kontrakan">
            <option value="Rumah Orang Tua">
        </datalist>
    </label>

    <label>
        <span>Nama Penerima</span>
        <input type="text" name="recipient_name" maxlength="100" value="<?= e((string) $address['recipient_name']) ?>" required>
    </label>

    <label>
        <span>Nomor Telepon</span>
        <input type="tel" name="phone" maxlength="20" value="<?= e((string) $address['phone']) ?>" placeholder="08xxxxxxxxxx" required>
    </label>

    <label>
        <span>Provinsi</span>
        <input type="text" name="province" maxlength="100" value="<?= e((string) $address['province']) ?>" placeholder="Contoh: Jawa Tengah" required>
    </label>

    <label>
        <span>Kota/Kabupaten</span>
        <input type="text" name="city" maxlength="100" value="<?= e((string) $address['city']) ?>" placeholder="Contoh: Rembang" required>
    </label>

    <label>
        <span>Kecamatan</span>
        <input type="text" name="district" maxlength="100" value="<?= e((string) $address['district']) ?>" required>
    </label>

    <label>
        <span>Kelurahan/Desa <small>(opsional)</small></span>
        <input type="text" name="village" maxlength="100" value="<?= e((string) $address['village']) ?>">
    </label>

    <label>
        <span>Kode Pos</span>
        <input type="text" name="postal_code" inputmode="numeric" maxlength="5" pattern="[0-9]{5}" value="<?= e((string) $address['postal_code']) ?>" placeholder="5 angka" required>
    </label>
</div>

<label>
    <span>Alamat Lengkap</span>
    <textarea name="full_address" rows="5" maxlength="1000" placeholder="Nama jalan, nomor rumah, RT/RW, patokan lokasi..." required><?= e((string) $address['full_address']) ?></textarea>
</label>

<label>
    <span>Catatan untuk Kurir <small>(opsional)</small></span>
    <textarea name="courier_note" rows="3" maxlength="255" placeholder="Contoh: Rumah pagar hitam, titip ke satpam... "><?= e((string) $address['courier_note']) ?></textarea>
    <small class="form-helper">Catatan ini akan disalin ke pesanan saat checkout.</small>
</label>

<label class="address-primary-check">
    <input type="checkbox" name="is_primary" value="1" <?= (int) $address['is_primary'] === 1 ? 'checked' : '' ?>>
    <span><strong>Jadikan alamat utama</strong><small>Alamat utama otomatis dipilih saat checkout.</small></span>
</label>

<input type="hidden" name="return_to" value="<?= e($returnTo) ?>">

<div class="address-form-actions">
    <a class="button button-secondary" href="addresses.php?return_to=<?= e($returnTo) ?>">Batal</a>
    <button class="button button-primary" type="submit"><?= e($submitLabel) ?></button>
</div>
