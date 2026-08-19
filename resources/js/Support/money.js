/**
 * Naira formatting for the browser.
 *
 * The server sends formatted strings for anything it has already totalled;
 * this is for the running totals a page works out while the buyer is still
 * choosing — a delivery method, a quantity — and it must agree with
 * App\Support\Money exactly.
 */
export function naira(kobo) {
    const amount = (Number(kobo) || 0) / 100;

    return (
        '₦' +
        amount.toLocaleString('en-NG', {
            minimumFractionDigits: kobo % 100 === 0 ? 0 : 2,
            maximumFractionDigits: kobo % 100 === 0 ? 0 : 2,
        })
    );
}
