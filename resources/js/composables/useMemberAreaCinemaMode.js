import { ref } from 'vue';

const cinemaMode = ref(false);

export function useMemberAreaCinemaMode() {
    function setCinemaMode(value) {
        cinemaMode.value = !!value;
    }

    function toggleCinemaMode() {
        cinemaMode.value = !cinemaMode.value;
    }

    return { cinemaMode, setCinemaMode, toggleCinemaMode };
}
