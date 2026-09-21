module.exports = {
  content: ["./public/**/*.html", "./public/assets/js/**/*.js"],
  theme: {
    extend: {
      fontFamily: { bengali: ['"Noto Sans Bengali"', "Arial", "sans-serif"] },
      colors: { primary: "#5b46e8", "primary-dark": "#4935cc", sale: "#f97316", use: "#ef4444", both: "#8b5cf6" },
      boxShadow: { soft: "0 18px 55px rgba(26,34,58,.09)" },
    },
  },
  plugins: [],
};
