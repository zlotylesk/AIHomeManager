Przegląd kodu dla bieżącego brancha przed PR.

1. `git diff develop...HEAD --name-only` — lista zmienionych plików.
2. `make phpstan && make cs-check && make deptrac`.
3. Analiza zmian:
   - hexagonal — Domain bez zewnętrznych zależności
   - brakujące interfejsy nowych portów
   - VO bez walidacji w konstruktorze
   - handlery CQRS bez testów
   - Doctrine XML — mapping dla nowych encji
4. Raport pogrupowany: **Krytyczne / Ważne / Sugestie**.
5. Konkretne poprawki dla Krytycznych.

⛔ STOP — przedstaw raport, czekaj na decyzję.