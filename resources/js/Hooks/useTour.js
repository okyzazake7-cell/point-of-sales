import { usePage } from "@inertiajs/react";
import { useEffect, useRef, useState, useCallback } from "react";
import axios from "axios";
import { driver } from "driver.js";
import "driver.js/dist/driver.css";
import { dashboardTour, posTour, productsTour, cashierShiftsTour, reportsTour } from "@/Utils/tours";
import i18n from "@/i18n";

const TOURS = {
  dashboard: dashboardTour,
  pos: posTour,
  products: productsTour,
  cashier_shifts: cashierShiftsTour,
  reports: reportsTour,
};

export function useTour(tourName) {
  const { auth } = usePage().props;
  const driverObj = useRef(null);
  const [isActive, setIsActive] = useState(false);

  const markCompleted = useCallback(() => {
    // ponytail: plain axios — Inertia router rejects the JSON-only response
    axios.post(route("tours.complete", { tour: tourName }), {}, {
      headers: { Accept: "application/json" },
    }).catch(() => {});
  }, [tourName]);

  const start = useCallback(() => {
    if (driverObj.current) return;

    // Skip steps whose target is not rendered (conditional elements)
    const base = TOURS[tourName] ?? {};
    const steps = (base.steps ?? []).filter(
      (s) => !s.element || document.querySelector(s.element)
    );
    if (steps.length === 0) return;

    driverObj.current = driver({
      // Tombol bawaan driver.js berbahasa Inggris ("Next", "1 of 6") di atas
      // isi tur yang berbahasa Indonesia — diterjemahkan lewat i18n.
      nextBtnText: i18n.t("tour.next"),
      prevBtnText: i18n.t("tour.prev"),
      doneBtnText: i18n.t("tour.done"),
      progressText: `{{current}} ${i18n.t("tour.of")} {{total}}`,
      ...base,
      steps,
      onDestroyed: () => {
        setIsActive(false);
        driverObj.current = null;
        markCompleted();
      },
    });
    setIsActive(true);
    driverObj.current.drive();
  }, [tourName, markCompleted]);

  useEffect(() => {
    const completed = auth?.completedTours ?? [];
    if (!completed.includes(tourName)) {
      const t = setTimeout(start, 600);
      return () => clearTimeout(t);
    }
  }, [auth?.completedTours, tourName, start]);

  useEffect(() => {
    return () => {
      if (driverObj.current) {
        driverObj.current.destroy();
        driverObj.current = null;
      }
    };
  }, []);

  return { start, isActive };
}
